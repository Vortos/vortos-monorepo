<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\SdkKey;

use Symfony\Component\Uid\Uuid;
use Vortos\FeatureFlags\SdkKey\Storage\SdkKeyStorageInterface;

class SdkKeyService
{
    public function __construct(
        private readonly SdkKeyStorageInterface $storage,
        private readonly IpAllowlistChecker $ipChecker,
    ) {}

    /**
     * Issue a new SDK key for a project + environment.
     *
     * @return array{rawKey: string, sdkKey: SdkKey}
     */
    public function issue(
        string $name,
        string $projectId,
        string $environment,
        string $kind,
        string $createdBy,
        ?array $ipAllowlist,
        ?\DateTimeImmutable $expiresAt,
    ): array {
        $rawKey  = $this->generateRawKey($kind);
        $keyHash = hash('sha256', $rawKey);
        // First 12 chars after the `vff_srv_` / `vff_cli_` prefix.
        $prefix  = substr($rawKey, 8, 12);

        $sdkKey = new SdkKey(
            id:          Uuid::v7()->toRfc4122(),
            name:        $name,
            keyPrefix:   $prefix,
            keyHash:     $keyHash,
            kind:        $kind,
            projectId:   $projectId,
            environment: $environment,
            createdAt:   new \DateTimeImmutable(),
            createdBy:   $createdBy,
            expiresAt:   $expiresAt,
            ipAllowlist: $ipAllowlist,
        );

        $this->storage->save($sdkKey);

        return ['rawKey' => $rawKey, 'sdkKey' => $sdkKey];
    }

    /**
     * Validate a raw key against the stored hash.
     * Returns the SdkKey on success, null on any failure.
     * Performs a timing-safe hash comparison.
     */
    public function validate(string $rawKey, string $projectId, string $environment, ?string $remoteIp = null): ?SdkKey
    {
        if (strlen($rawKey) < 8) {
            return null;
        }

        $prefix = substr($rawKey, 8, 12);

        if (strlen($prefix) < 12) {
            return null;
        }

        $candidates = $this->storage->findByPrefix($prefix);

        if (empty($candidates)) {
            return null;
        }

        $incoming = hash('sha256', $rawKey);
        $matched  = null;

        foreach ($candidates as $candidate) {
            // hash_equals is timing-safe — always compare even on mismatches.
            if (hash_equals($candidate->keyHash, $incoming)) {
                $matched = $candidate;
                break;
            }
        }

        if ($matched === null) {
            return null;
        }

        if (!$matched->isActive()) {
            return null;
        }

        if ($matched->projectId !== $projectId || $matched->environment !== $environment) {
            return null;
        }

        if ($matched->ipAllowlist !== null && !empty($matched->ipAllowlist) && $remoteIp !== null) {
            if (!$this->ipChecker->isAllowed($remoteIp, $matched->ipAllowlist)) {
                return null;
            }
        }

        // Fire-and-forget last-used update; swallow any errors to keep the hot path safe.
        try {
            $this->storage->updateLastUsed($matched->id, new \DateTimeImmutable());
        } catch (\Throwable) {
        }

        return $matched;
    }

    public function revoke(string $id, string $actorId): void
    {
        $this->storage->revoke($id, new \DateTimeImmutable());
    }

    /**
     * Rotate a key: issue a successor with a 24h grace period on the old key.
     *
     * @return array{rawKey: string, sdkKey: SdkKey}
     */
    public function rotate(string $id, string $actorId): array
    {
        $existing = $this->storage->findById($id);

        if ($existing === null) {
            throw new \InvalidArgumentException(sprintf('SDK key "%s" not found.', $id));
        }

        $result     = $this->issue(
            $existing->name . ' (rotated)',
            $existing->projectId,
            $existing->environment,
            $existing->kind,
            $actorId,
            $existing->ipAllowlist,
            $existing->expiresAt,
        );
        $successor  = $result['sdkKey'];

        // Update the old key to reference the successor + set grace period.
        $gracePeriodEndsAt = new \DateTimeImmutable('+24 hours');
        $updated = new SdkKey(
            id:                 $existing->id,
            name:               $existing->name,
            keyPrefix:          $existing->keyPrefix,
            keyHash:            $existing->keyHash,
            kind:               $existing->kind,
            projectId:          $existing->projectId,
            environment:        $existing->environment,
            createdAt:          $existing->createdAt,
            createdBy:          $existing->createdBy,
            successorKeyId:     $successor->id,
            gracePeriodEndsAt:  $gracePeriodEndsAt,
            expiresAt:          $existing->expiresAt,
            revokedAt:          $existing->revokedAt,
            lastUsedAt:         $existing->lastUsedAt,
            ipAllowlist:        $existing->ipAllowlist,
        );

        $this->storage->save($updated);

        return $result;
    }

    private function generateRawKey(string $kind): string
    {
        $kindSlug = $kind === SdkKey::KIND_CLIENT ? 'cli' : 'srv';
        $random   = random_bytes(20);
        $encoded  = rtrim(strtr(base64_encode($random), '+/', '-_'), '=');

        return 'vff_' . $kindSlug . '_' . $encoded;
    }
}
