<?php

declare(strict_types=1);

namespace Vortos\Health\Tests\Conformance;

use Vortos\Health\Probe\Capacity\CapacityReader\InMemoryCapacityReader;
use Vortos\Health\Probe\Capacity\CpuLoadProbe;
use Vortos\Health\Probe\HealthProbeInterface;
use Vortos\Health\Probe\ProbeKind;
use Vortos\Health\Testing\HealthProbeConformanceTestCase;

final class CpuLoadProbeConformanceTest extends HealthProbeConformanceTestCase
{
    protected function createProbe(): HealthProbeInterface
    {
        return new CpuLoadProbe(new InMemoryCapacityReader(cpuLoadPct: 10.0));
    }

    protected function expectedKey(): string
    {
        return 'cpu-load';
    }

    protected function expectedKind(): ProbeKind
    {
        return ProbeKind::Readiness;
    }
}
