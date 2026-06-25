<?php

declare(strict_types=1);

namespace Vortos\Health\Tests\Unit\Preflight;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Vortos\Deploy\Definition\DeploymentDefinition;
use Vortos\Deploy\Definition\EnvironmentName;
use Vortos\Deploy\Plan\CurrentDeployState;
use Vortos\Deploy\Preflight\PreflightContext;
use Vortos\Deploy\Preflight\PreflightStatus;
use Vortos\Deploy\Strategy\DeployStrategy;
use Vortos\Deploy\Target\ActiveColor;
use Vortos\Health\Preflight\DetectorIndependenceDoctorCheck;
use Vortos\Health\Probe\HealthProbeRegistry;
use Vortos\Health\Tests\Fixtures\FakeUptimeMonitor;
use Vortos\Health\Tests\Fixtures\StubProbe;
use Vortos\Health\Uptime\Driver\Null\NullUptimeMonitor;
use Vortos\Health\Uptime\UptimeMonitorRegistry;
use Vortos\Release\Manifest\Arch;
use Vortos\Release\Manifest\BuildManifest;
use Vortos\Release\Schema\SchemaFingerprint;

final class DetectorIndependenceDoctorCheckTest extends TestCase
{
    /** @param array<string, object> $probes */
    private function probeRegistry(array $probes = []): HealthProbeRegistry
    {
        $container = new class($probes) implements ContainerInterface {
            public function __construct(private array $probes) {}

            public function get(string $id): mixed
            {
                return $this->probes[$id];
            }

            public function has(string $id): bool
            {
                return isset($this->probes[$id]);
            }

            public function getProvidedServices(): array
            {
                return $this->probes;
            }
        };

        return new HealthProbeRegistry($container);
    }

    private function uptimeRegistryWithFake(): UptimeMonitorRegistry
    {
        $container = new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                return match ($id) {
                    'fake' => new FakeUptimeMonitor(),
                    'null' => new NullUptimeMonitor(),
                };
            }

            public function has(string $id): bool
            {
                return in_array($id, ['fake', 'null'], true);
            }
        };

        return new UptimeMonitorRegistry($container);
    }

    private function contextFor(string $env): PreflightContext
    {
        $definition = DeploymentDefinition::build(
            host: 'fake-target',
            registry: 'fake-registry',
            credential: 'fake-credential',
            strategy: DeployStrategy::BlueGreen,
            arch: Arch::Arm64,
            autoRollback: true,
        );

        $manifest = new BuildManifest(
            buildId: 'build-1',
            gitSha: 'abc1234',
            imageDigest: 'sha256:' . str_repeat('a', 64),
            targetArch: Arch::Arm64,
            environment: $env,
            schemaFingerprint: new SchemaFingerprint(['m001']),
            createdAt: new DateTimeImmutable('2026-01-01'),
        );

        $state = new CurrentDeployState(
            activeColor: ActiveColor::Blue,
            currentDigest: 'sha256:' . str_repeat('a', 64),
            appliedFingerprint: new SchemaFingerprint(['m001']),
            pendingContractMigrations: [],
        );

        return new PreflightContext($definition, $manifest, $state, new EnvironmentName($env));
    }

    public function testPassesWhenAllThreeDetectorsConfigured(): void
    {
        $check = new DetectorIndependenceDoctorCheck(
            $this->probeRegistry(['disk-capacity' => StubProbe::readiness('disk-capacity')]),
            $this->uptimeRegistryWithFake(),
            'fake',
            heartbeatConfigured: true,
        );

        $finding = $check->check($this->contextFor('prod'));

        self::assertSame(PreflightStatus::Pass, $finding->status);
    }

    public function testFailsClosedInProdWithFewerThanThreeDetectors(): void
    {
        $check = new DetectorIndependenceDoctorCheck(
            $this->probeRegistry(['disk-capacity' => StubProbe::readiness('disk-capacity')]),
            $this->uptimeRegistryWithFake(),
            'null', // null driver declares no real capabilities -> not a real third detector
            heartbeatConfigured: false,
        );

        $finding = $check->check($this->contextFor('prod'));

        self::assertSame(PreflightStatus::Fail, $finding->status);
    }

    public function testDoesNotFailClosedOutsideProd(): void
    {
        $check = new DetectorIndependenceDoctorCheck(
            $this->probeRegistry(['disk-capacity' => StubProbe::readiness('disk-capacity')]),
            $this->uptimeRegistryWithFake(),
            'null',
            heartbeatConfigured: false,
        );

        $finding = $check->check($this->contextFor('staging'));

        self::assertSame(PreflightStatus::Pass, $finding->status);
        self::assertStringContainsString('not fail-closed', $finding->summary);
    }

    public function testNullDriverIsNeverCountedAsARealSyntheticProber(): void
    {
        $check = new DetectorIndependenceDoctorCheck(
            $this->probeRegistry(['disk-capacity' => StubProbe::readiness('disk-capacity')]),
            $this->uptimeRegistryWithFake(),
            'null',
            heartbeatConfigured: true,
        );

        $finding = $check->check($this->contextFor('production'));

        // 2 of 3 (in-app probes + heartbeat) -> still fails closed in prod.
        self::assertSame(PreflightStatus::Fail, $finding->status);
    }

    public function testMissingUptimeRegistryIsHandledGracefully(): void
    {
        $check = new DetectorIndependenceDoctorCheck(
            $this->probeRegistry(['disk-capacity' => StubProbe::readiness('disk-capacity')]),
            null,
            'fake',
            heartbeatConfigured: true,
        );

        $finding = $check->check($this->contextFor('prod'));

        self::assertSame(PreflightStatus::Fail, $finding->status);
    }

    public function testNoProbesAtAllStillCountsOtherDetectors(): void
    {
        $check = new DetectorIndependenceDoctorCheck(
            $this->probeRegistry([]),
            $this->uptimeRegistryWithFake(),
            'fake',
            heartbeatConfigured: true,
        );

        $finding = $check->check($this->contextFor('prod'));

        // Only heartbeat + synthetic prober (2 of 3) -> fails closed in prod.
        self::assertSame(PreflightStatus::Fail, $finding->status);
    }
}
