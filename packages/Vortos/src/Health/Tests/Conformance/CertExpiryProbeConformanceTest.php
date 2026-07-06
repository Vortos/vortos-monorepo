<?php

declare(strict_types=1);

namespace Vortos\Health\Tests\Conformance;

use Vortos\Health\Probe\Cert\CertExpiryProbe;
use Vortos\Health\Probe\Cert\CertInspectionResult;
use Vortos\Health\Probe\Cert\InMemoryCertInspector;
use Vortos\Health\Probe\HealthProbeInterface;
use Vortos\Health\Probe\ProbeKind;
use Vortos\Health\Testing\HealthProbeConformanceTestCase;

final class CertExpiryProbeConformanceTest extends HealthProbeConformanceTestCase
{
    protected function createProbe(): HealthProbeInterface
    {
        $inspector = (new InMemoryCertInspector())
            ->withResult('example.test', 443, CertInspectionResult::ok(30));

        return new CertExpiryProbe($inspector, 'example.test');
    }

    protected function expectedKey(): string
    {
        return 'cert-expiry';
    }

    protected function expectedKind(): ProbeKind
    {
        // GAP-F: cert-expiry is monitoring-only — never a readiness gate.
        return ProbeKind::Monitoring;
    }
}
