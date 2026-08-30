<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Preflight;

use Throwable;
use Vortos\Deploy\Preflight\PreflightCategory;
use Vortos\Deploy\Preflight\PreflightCheckInterface;
use Vortos\Deploy\Preflight\PreflightContext;
use Vortos\Deploy\Preflight\PreflightFinding;
use Vortos\Foundation\Health\Contract\HealthCheckInterface;

/**
 * Refuses a deploy whose object store is unreachable — the interlock that moving
 * {@see \Vortos\ObjectStore\Health\S3ObjectStoreHealthCheck} to Monitoring would otherwise have
 * given away.
 *
 * ## The gap this closes
 *
 * While the bucket check gated readiness it did two jobs at once, and only one of them was wrong.
 * The wrong one was continuous: a provider blip failing readiness on every replica at the same
 * instant, emptying the edge's upstream pool and taking the whole site down over a subsystem most
 * requests never touch. That job is correctly gone.
 *
 * The other job was real. A fresh color whose credentials are wrong, whose bucket was renamed, or
 * whose endpoint points at the wrong account would fail readiness and abort the cutover. Off the
 * gate, that same color comes up healthy and serves traffic with every upload silently broken —
 * and the first person to find out is a user losing a passport scan. Removing a bad gate should not
 * cost a good interlock.
 *
 * ## Why a preflight instead
 *
 * The two jobs differ in WHEN they need answering, which is the whole reason readiness was the wrong
 * home for the second one. "Is this build correctly configured?" is asked once, at deploy time, and
 * a wrong answer should stop the deploy. "Is the provider up right now?" is asked continuously, and
 * a wrong answer must never stop traffic. Readiness could only express the second, so it was made to
 * carry the first — at the price of running it every few seconds forever.
 *
 * Answered here it costs ONE HeadBucket per deploy instead of one per probe interval per container
 * per color, for the entire life of the deployment, which on a metered provider is the difference
 * between a rounding error and a line item. The doctor is also the honest place for it: a
 * misconfigured bucket is a deploy defect, and this is where deploy defects are reported.
 *
 * Fail-closed, and deliberately not environment-scoped the way
 * {@see \Vortos\Health\Preflight\DetectorIndependenceDoctorCheck} is: an unreachable bucket in
 * staging is the same misconfiguration it would be in production, caught one environment earlier,
 * which is the point of having a staging environment at all. Skips only when there is no store to
 * check — the log/null drivers, where no bucket exists to be wrong about.
 */
final class ObjectStoreReachableDoctorCheck implements PreflightCheckInterface
{
    public function __construct(
        /** Null on the log/null drivers, where the check is meaningless rather than failing. */
        private readonly ?HealthCheckInterface $storeCheck,
        private readonly string $driver,
        private readonly string $provider,
        private readonly string $bucket,
    ) {}

    public function id(): string
    {
        return 'object_store.reachable';
    }

    public function category(): PreflightCategory
    {
        // Credential, not Capability: in practice the ways this fails are a rotated key, a key
        // scoped to the wrong bucket, or an endpoint pointing at another account. Those are
        // credential defects, and grouping it there puts it next to the findings an operator would
        // already be reading when the answer is "the deploy cannot talk to its own storage".
        return PreflightCategory::Credential;
    }

    public function check(PreflightContext $context): PreflightFinding
    {
        if ($this->storeCheck === null) {
            return PreflightFinding::skip(
                $this->id(),
                $this->category(),
                sprintf('Object store driver is "%s" — no remote bucket to reach.', $this->driver),
            );
        }

        if ($this->bucket === '') {
            return PreflightFinding::fail(
                $this->id(),
                $this->category(),
                'Object store bucket is not configured.',
                sprintf('Driver is "%s" (provider "%s") but no bucket name was set.', $this->driver, $this->provider),
                'Set OBJECT_STORE_BUCKET in the environment this deploy delivers.',
            );
        }

        try {
            $result = $this->storeCheck->check();
        } catch (Throwable $e) {
            // The interface permits a throw; the doctor would convert it to a Fail anyway, but
            // doing it here lets the finding carry the provider and bucket the operator needs.
            return $this->unreachable($e->getMessage());
        }

        if (!$result->healthy) {
            return $this->unreachable($result->error ?? $result->errorCode ?? 'unknown error');
        }

        return PreflightFinding::pass(
            $this->id(),
            $this->category(),
            sprintf('Object store bucket "%s" is reachable.', $this->bucket),
            sprintf('provider=%s driver=%s', $this->provider, $this->driver),
        );
    }

    private function unreachable(string $detail): PreflightFinding
    {
        return PreflightFinding::fail(
            $this->id(),
            $this->category(),
            sprintf('Object store bucket "%s" is unreachable.', $this->bucket),
            sprintf('provider=%s driver=%s: %s', $this->provider, $this->driver, $detail),
            'Check the object-store credentials, endpoint and bucket name in the delivered '
                . 'environment. This gate exists because the readiness probe no longer catches it: '
                . 'deploying past this ships a color that serves traffic with uploads broken.',
        );
    }
}
