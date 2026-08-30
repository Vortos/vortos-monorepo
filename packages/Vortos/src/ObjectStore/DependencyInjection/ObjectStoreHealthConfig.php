<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\DependencyInjection;

/**
 * Cold-start resilience for the object-store MONITORING probe (see S3ObjectStoreHealthCheck).
 *
 * A freshly (re)started worker pays for DNS + TLS + SDK init on its first bucket call; a
 * one-shot probe run at that instant can transiently fail even though the store is healthy.
 * These knobs let the probe retry a small, bounded number of times before reporting unhealthy,
 * so a cold-connection blip does not raise a false alert.
 *
 * The probe no longer gates traffic (it is HealthCheckKind::Monitoring), so a false negative here
 * costs an alert rather than a rolled-back deploy — but a quiet alert channel is worth keeping.
 */
final class ObjectStoreHealthConfig
{
    private int $coldStartAttempts     = 3;
    private int $coldStartBackoffMs     = 200;

    /**
     * HeadBucket attempts before reporting unhealthy. 1 disables cold-start retry.
     */
    public function coldStartAttempts(int $attempts): static
    {
        $this->coldStartAttempts = max(1, $attempts);
        return $this;
    }

    /**
     * Backoff between attempts, in milliseconds. Kept small so total added latency stays
     * well within the probe timeout budget.
     */
    public function coldStartBackoffMilliseconds(int $milliseconds): static
    {
        $this->coldStartBackoffMs = max(0, $milliseconds);
        return $this;
    }

    /**
     * @internal
     *
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'cold_start_attempts'            => $this->coldStartAttempts,
            'cold_start_backoff_milliseconds' => $this->coldStartBackoffMs,
        ];
    }
}
