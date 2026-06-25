<?php

declare(strict_types=1);

namespace Vortos\Deploy\PullAgent;

use Vortos\Deploy\Exception\ManifestReplayException;
use Vortos\Deploy\Exception\StaleManifestException;

final class ManifestFreshnessGuard
{
    /** @var array<string, int> env -> last applied version */
    private array $lastAppliedVersions = [];

    /** @var array<string, array<string, \DateTimeImmutable>> env -> nonce -> issuedAt */
    private array $seenNonces = [];

    public function __construct(
        private readonly int $freshnessWindowSeconds = 600,
    ) {
        if ($freshnessWindowSeconds < 1) {
            throw new \InvalidArgumentException(sprintf(
                'Freshness window must be >= 1 second, got %d.',
                $freshnessWindowSeconds,
            ));
        }
    }

    public function assertFresh(DesiredStateManifest $manifest, \DateTimeImmutable $now): void
    {
        $env = $manifest->env;

        $lastVersion = $this->lastAppliedVersions[$env] ?? 0;
        if ($manifest->version <= $lastVersion) {
            throw StaleManifestException::rollback($manifest->version, $lastVersion);
        }

        if (isset($this->seenNonces[$env][$manifest->nonce])) {
            throw ManifestReplayException::create($manifest->nonce);
        }

        $age = $now->getTimestamp() - $manifest->issuedAt->getTimestamp();
        if ($age > $this->freshnessWindowSeconds || $age < -60) {
            throw StaleManifestException::staleIssuedAt($manifest->issuedAt, $this->freshnessWindowSeconds);
        }
    }

    public function recordApplied(DesiredStateManifest $manifest): void
    {
        $env = $manifest->env;
        $this->lastAppliedVersions[$env] = $manifest->version;
        $this->seenNonces[$env][$manifest->nonce] = $manifest->issuedAt;
    }

    public function lastAppliedVersion(string $env): int
    {
        return $this->lastAppliedVersions[$env] ?? 0;
    }

    public function loadState(FreshnessSnapshot $snapshot): void
    {
        $this->lastAppliedVersions[$snapshot->env] = $snapshot->lastAppliedVersion;
        $this->seenNonces[$snapshot->env] = $snapshot->seenNonces;
    }

    /**
     * A nonce older than the freshness window (plus clock-skew slack) would already be
     * rejected by the issuedAt staleness check in {@see assertFresh()} if it were ever
     * replayed, so pruning it here keeps persisted storage bounded instead of growing
     * forever across the lifetime of an environment.
     */
    public function snapshot(string $env, \DateTimeImmutable $now): FreshnessSnapshot
    {
        $cutoff = $now->getTimestamp() - $this->freshnessWindowSeconds - 60;

        $nonces = array_filter(
            $this->seenNonces[$env] ?? [],
            static fn (\DateTimeImmutable $issuedAt): bool => $issuedAt->getTimestamp() >= $cutoff,
        );

        return new FreshnessSnapshot($env, $this->lastAppliedVersions[$env] ?? 0, $nonces);
    }
}
