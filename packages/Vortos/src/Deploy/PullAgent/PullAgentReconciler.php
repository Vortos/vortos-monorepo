<?php

declare(strict_types=1);

namespace Vortos\Deploy\PullAgent;

use Vortos\Deploy\Cutover\ReconcileRateLimiter;

final class PullAgentReconciler
{
    public function __construct(
        private readonly ManifestSourceInterface $source,
        private readonly ManifestVerifierInterface $verifier,
        private readonly ManifestFreshnessGuard $freshnessGuard,
        private readonly ManifestFreshnessStoreInterface $freshnessStore,
        private readonly DesiredStateApplier $applier,
        private readonly ReconcileRateLimiter $rateLimiter,
    ) {}

    public function reconcile(string $env): PullAgentReconcileResult
    {
        $this->freshnessGuard->loadState($this->freshnessStore->loadFreshnessState($env));

        $signed = $this->source->latest($env);

        if ($signed === null) {
            return new PullAgentReconcileResult(
                applied: false,
                alreadyCurrent: false,
                detail: 'no manifest available',
            );
        }

        $this->verifier->verify($signed);

        $manifest = $signed->manifest;
        $now = new \DateTimeImmutable();

        $lastVersion = $this->freshnessGuard->lastAppliedVersion($env);
        if ($manifest->version === $lastVersion) {
            return new PullAgentReconcileResult(
                applied: false,
                alreadyCurrent: true,
                detail: sprintf('version %d already applied', $manifest->version),
                appliedVersion: $manifest->version,
            );
        }

        $this->freshnessGuard->assertFresh($manifest, $now);

        if (!$this->rateLimiter->allow($env)) {
            return new PullAgentReconcileResult(
                applied: false,
                alreadyCurrent: false,
                detail: 'rate-limited',
            );
        }

        $this->applier->apply($manifest);
        $this->freshnessGuard->recordApplied($manifest);
        $this->freshnessStore->saveFreshnessState($env, $this->freshnessGuard->snapshot($env, $now));
        $this->rateLimiter->record($env);

        return new PullAgentReconcileResult(
            applied: true,
            alreadyCurrent: false,
            detail: sprintf('applied version %d', $manifest->version),
            appliedVersion: $manifest->version,
        );
    }
}
