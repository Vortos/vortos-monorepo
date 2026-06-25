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
use Vortos\Health\Preflight\LivenessIndependenceDoctorCheck;
use Vortos\Health\Probe\HealthProbeRegistry;
use Vortos\Health\Probe\ProbeKind;
use Vortos\Health\Tests\Fixtures\StubProbe;
use Vortos\Release\Manifest\Arch;
use Vortos\Release\Manifest\BuildManifest;
use Vortos\Release\Schema\SchemaFingerprint;

final class LivenessIndependenceDoctorCheckTest extends TestCase
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

    private function context(): PreflightContext
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
            environment: 'prod',
            schemaFingerprint: new SchemaFingerprint(['m001']),
            createdAt: new DateTimeImmutable('2026-01-01'),
        );

        $state = new CurrentDeployState(
            activeColor: ActiveColor::Blue,
            currentDigest: 'sha256:' . str_repeat('a', 64),
            appliedFingerprint: new SchemaFingerprint(['m001']),
            pendingContractMigrations: [],
        );

        return new PreflightContext($definition, $manifest, $state, new EnvironmentName('prod'));
    }

    public function testPassesWhenNoLivenessProbeDeclaresDependencyCheck(): void
    {
        $check = new LivenessIndependenceDoctorCheck($this->probeRegistry([
            'liveness' => StubProbe::liveness('liveness'),
            'readiness' => StubProbe::readiness('readiness'),
        ]));

        $finding = $check->check($this->context());

        self::assertSame(PreflightStatus::Pass, $finding->status);
    }

    public function testFailsClosedWhenALivenessProbeDeclaresDependencyCheck(): void
    {
        $check = new LivenessIndependenceDoctorCheck($this->probeRegistry([
            'bad-liveness' => new StubProbe('bad-liveness', ProbeKind::Liveness, true),
        ]));

        $finding = $check->check($this->context());

        self::assertSame(PreflightStatus::Fail, $finding->status);
        self::assertStringContainsString('bad-liveness', $finding->summary);
    }

    public function testPassesWhenThereAreNoProbesAtAll(): void
    {
        $check = new LivenessIndependenceDoctorCheck($this->probeRegistry([]));

        $finding = $check->check($this->context());

        self::assertSame(PreflightStatus::Pass, $finding->status);
    }

    public function testReadinessAndStartupProbesAreIgnored(): void
    {
        $check = new LivenessIndependenceDoctorCheck($this->probeRegistry([
            'readiness-with-dependency' => StubProbe::readiness('readiness-with-dependency'),
            'startup' => StubProbe::startup('startup'),
        ]));

        $finding = $check->check($this->context());

        self::assertSame(PreflightStatus::Pass, $finding->status);
    }
}
