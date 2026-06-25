<?php

declare(strict_types=1);

namespace Vortos\Deploy\PullAgent;

/**
 * Persists {@see ManifestFreshnessGuard} state across process restarts. deploy:agent
 * is a one-shot CLI command (typically cron-driven) — without persistence, every
 * invocation starts with empty anti-replay/anti-rollback state, and a captured
 * correctly-signed manifest could be replayed indefinitely or an old version reapplied
 * undetected. Implemented by the same backend driver classes as {@see
 * \Vortos\Deploy\State\CurrentReleaseStoreInterface}.
 */
interface ManifestFreshnessStoreInterface
{
    /**
     * Never returns null — an env with no persisted state yields {@see
     * FreshnessSnapshot::empty()}, so callers don't need a null check before hydrating
     * {@see ManifestFreshnessGuard}.
     */
    public function loadFreshnessState(string $env): FreshnessSnapshot;

    public function saveFreshnessState(string $env, FreshnessSnapshot $snapshot): void;
}
