<?php

declare(strict_types=1);

namespace Vortos\Health\Tests\Conformance;

use Vortos\Health\Probe\HealthProbeInterface;
use Vortos\Health\Probe\ProbeKind;
use Vortos\Health\Testing\HealthProbeConformanceTestCase;
use Vortos\Health\Tests\Fixtures\StubProbe;

final class StubProbeConformanceTest extends HealthProbeConformanceTestCase
{
    protected function createProbe(): HealthProbeInterface
    {
        return StubProbe::readiness('stub');
    }

    protected function expectedKey(): string
    {
        return 'stub';
    }

    protected function expectedKind(): ProbeKind
    {
        return ProbeKind::Readiness;
    }
}
