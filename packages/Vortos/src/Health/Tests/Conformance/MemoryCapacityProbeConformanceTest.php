<?php

declare(strict_types=1);

namespace Vortos\Health\Tests\Conformance;

use Vortos\Health\Probe\Capacity\CapacityReader\InMemoryCapacityReader;
use Vortos\Health\Probe\Capacity\MemoryCapacityProbe;
use Vortos\Health\Probe\HealthProbeInterface;
use Vortos\Health\Probe\ProbeKind;
use Vortos\Health\Testing\HealthProbeConformanceTestCase;

final class MemoryCapacityProbeConformanceTest extends HealthProbeConformanceTestCase
{
    protected function createProbe(): HealthProbeInterface
    {
        return new MemoryCapacityProbe(new InMemoryCapacityReader(memoryUsedPct: 10.0));
    }

    protected function expectedKey(): string
    {
        return 'memory-capacity';
    }

    protected function expectedKind(): ProbeKind
    {
        return ProbeKind::Readiness;
    }
}
