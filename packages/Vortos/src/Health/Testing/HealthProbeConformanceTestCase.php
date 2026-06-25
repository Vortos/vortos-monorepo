<?php

declare(strict_types=1);

namespace Vortos\Health\Testing;

use Vortos\Health\Probe\Capability\HealthCapability;
use Vortos\Health\Probe\HealthProbeInterface;
use Vortos\Health\Probe\ProbeKind;
use Vortos\Health\Probe\ProbeResult;
use Vortos\Health\Probe\ProbeStatus;
use Vortos\OpsKit\Testing\ConformanceTestCase;

abstract class HealthProbeConformanceTestCase extends ConformanceTestCase
{
    abstract protected function createProbe(): HealthProbeInterface;

    abstract protected function expectedKind(): ProbeKind;

    protected function createDriver(): HealthProbeInterface
    {
        return $this->createProbe();
    }

    final public function testNameIsNonEmpty(): void
    {
        $probe = $this->createProbe();

        self::assertNotEmpty($probe->name());
        self::assertMatchesRegularExpression('/^[a-z][a-z0-9_-]*$/', $probe->name());
    }

    final public function testKindMatchesExpected(): void
    {
        $probe = $this->createProbe();

        self::assertSame($this->expectedKind(), $probe->kind());
    }

    final public function testCheckReturnsProbeResult(): void
    {
        $probe = $this->createProbe();
        $result = $probe->check();

        self::assertInstanceOf(ProbeResult::class, $result);
        self::assertSame($probe->name(), $result->name);
        self::assertSame($probe->kind(), $result->kind);
    }

    final public function testCheckResultHasValidStatus(): void
    {
        $result = $this->createProbe()->check();

        self::assertContains($result->status, ProbeStatus::cases());
    }

    final public function testCheckResultLatencyIsNonNegative(): void
    {
        $result = $this->createProbe()->check();

        self::assertGreaterThanOrEqual(0.0, $result->latencyMs);
    }

    final public function testCapabilitiesDeclareDependencyCheckHonestly(): void
    {
        $probe = $this->createProbe();
        $caps = $probe->capabilities();

        if ($probe->kind() === ProbeKind::Liveness) {
            self::assertFalse(
                $caps->supports(HealthCapability::DependencyCheck),
                'Liveness probes must NOT declare dependency_check=true (would cause restart storms on dep failures).',
            );
        } else {
            self::assertInstanceOf(\Vortos\OpsKit\Driver\Capability\CapabilityDescriptor::class, $caps);
        }
    }

    final public function testPublicArrayNeverLeaksDetail(): void
    {
        $result = $this->createProbe()->check();
        $public = $result->toPublicArray();

        self::assertArrayHasKey('status', $public);
        self::assertCount(1, $public);
    }

    final public function testDetailedArrayContainsRequiredFields(): void
    {
        $result = $this->createProbe()->check();
        $detailed = $result->toDetailedArray();

        self::assertArrayHasKey('status', $detailed);
        self::assertArrayHasKey('kind', $detailed);
        self::assertArrayHasKey('latency_ms', $detailed);
    }
}
