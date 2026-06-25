<?php

declare(strict_types=1);

namespace Vortos\Deploy\Canary;

/**
 * Seam: Deploy declares this port; Observability (or any metrics backend) implements it.
 * Fail-closed: implementations must return CanaryInstantResult::empty() on error, never throw.
 */
interface CanaryMetricsPort
{
    public function instant(string $indicatorRef, string $color): CanaryInstantResult;
}
