<?php

declare(strict_types=1);

namespace Vortos\Observability\Audit;

/**
 * Pure hash-chain + HMAC signing discipline for the audit ledger (Block 16, §4.1).
 *
 * `contentHash = sha256(canonicalJson(entry-without-hash) . prevHash)` — chains each
 * entry to its predecessor so any silent rewrite of a past row changes every hash
 * after it. `signature = HMAC-SHA256(signingMessage, key)` proves authorship (the
 * key lives off-host via `vortos-secrets`, read at use-time, never logged — callers
 * pass the revealed key string directly into this pure function).
 *
 * Mirrors the signing discipline of {@see \Vortos\FeatureFlags\Compliance\Export\SignedManifest}.
 */
final class AuditHashChain
{
    /** sha256('') — the genesis previous-hash for the first entry in a chain. */
    public const GENESIS_HASH = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    /**
     * @param array<string, mixed> $hashableFields Entry fields excluding prevHash/contentHash/signature
     */
    public function contentHash(array $hashableFields, string $prevHash): string
    {
        return hash('sha256', $this->canonicalJson($hashableFields) . $prevHash);
    }

    public function signingMessage(string $entryId, int $sequence, string $contentHash, string $prevHash): string
    {
        return implode(':', [$entryId, (string) $sequence, $contentHash, $prevHash]);
    }

    public function sign(string $signingMessage, string $hmacKey): string
    {
        if ($hmacKey === '') {
            throw new \InvalidArgumentException('Audit HMAC key must not be empty.');
        }

        return hash_hmac('sha256', $signingMessage, $hmacKey);
    }

    public function verifySignature(string $signingMessage, string $signature, string $hmacKey): bool
    {
        if ($hmacKey === '') {
            return false;
        }

        return hash_equals($this->sign($signingMessage, $hmacKey), $signature);
    }

    /**
     * Build a fully-chained, signed entry given the previous entry's content hash
     * (or {@see self::GENESIS_HASH} for the first entry in the chain).
     *
     * @param array<string, mixed> $data
     */
    public function chain(
        string $entryId,
        int $sequence,
        string $eventType,
        string $actorId,
        string $actorIdentitySource,
        string $env,
        string $buildId,
        string $gitSha,
        string $imageDigest,
        string $schemaFingerprintId,
        ?string $reason,
        string $occurredAt,
        array $data,
        string $prevHash,
        string $hmacKey,
    ): AuditEntry {
        $hashable = [
            'entry_id' => $entryId,
            'sequence' => $sequence,
            'event_type' => $eventType,
            'actor_id' => $actorId,
            'actor_identity_source' => $actorIdentitySource,
            'env' => $env,
            'build_id' => $buildId,
            'git_sha' => $gitSha,
            'image_digest' => $imageDigest,
            'schema_fingerprint_id' => $schemaFingerprintId,
            'reason' => $reason,
            'occurred_at' => $occurredAt,
            'data' => $data,
        ];

        $contentHash = $this->contentHash($hashable, $prevHash);
        $signingMessage = $this->signingMessage($entryId, $sequence, $contentHash, $prevHash);
        $signature = $this->sign($signingMessage, $hmacKey);

        return new AuditEntry(
            entryId: $entryId,
            sequence: $sequence,
            eventType: $eventType,
            actorId: $actorId,
            actorIdentitySource: $actorIdentitySource,
            env: $env,
            buildId: $buildId,
            gitSha: $gitSha,
            imageDigest: $imageDigest,
            schemaFingerprintId: $schemaFingerprintId,
            reason: $reason,
            occurredAt: $occurredAt,
            data: $data,
            prevHash: $prevHash,
            contentHash: $contentHash,
            signature: $signature,
        );
    }

    /**
     * Deterministic JSON over a recursively key-sorted structure — the same payload
     * always serializes identically, which is what makes the hash meaningful.
     *
     * @param array<string, mixed> $value
     */
    private function canonicalJson(array $value): string
    {
        return json_encode(
            $this->sortRecursively($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    private function sortRecursively(mixed $value): mixed
    {
        if (is_array($value)) {
            $isList = array_is_list($value);
            $sorted = [];
            foreach ($value as $k => $v) {
                $sorted[$k] = $this->sortRecursively($v);
            }
            if (!$isList) {
                ksort($sorted);
            }

            return $sorted;
        }

        return $value;
    }
}
