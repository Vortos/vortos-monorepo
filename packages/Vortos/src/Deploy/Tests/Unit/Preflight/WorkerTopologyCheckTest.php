<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Preflight;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Definition\DeploymentDefinition;
use Vortos\Deploy\Definition\EnvironmentName;
use Vortos\Deploy\Definition\WorkerTopology;
use Vortos\Deploy\Plan\CurrentDeployState;
use Vortos\Deploy\Preflight\Check\WorkerTopologyCheck;
use Vortos\Deploy\Preflight\PreflightContext;
use Vortos\Deploy\Preflight\PreflightStatus;
use Vortos\Release\Manifest\Arch;
use Vortos\Release\Manifest\BuildManifest;
use Vortos\Release\Schema\SchemaFingerprint;

/**
 * B20: the doctor fails closed when an external-supervisor worker topology is declared for a
 * deploy-in-image host that has no reachable supervisord.
 */
final class WorkerTopologyCheckTest extends TestCase
{
    public function test_ride_color_passes_on_ssh_compose(): void
    {
        $finding = (new WorkerTopologyCheck())->check(
            $this->context('ssh-compose', WorkerTopology::RideColor),
        );

        self::assertSame(PreflightStatus::Pass, $finding->status);
    }

    public function test_external_supervisor_fails_closed_on_ssh_compose(): void
    {
        $finding = (new WorkerTopologyCheck())->check(
            $this->context('ssh-compose', WorkerTopology::ExternalSupervisor),
        );

        self::assertSame(PreflightStatus::Fail, $finding->status);
        self::assertTrue($finding->isFailure());
    }

    public function test_external_supervisor_passes_on_a_host_with_supervisord(): void
    {
        $finding = (new WorkerTopologyCheck())->check(
            $this->context('bare-metal', WorkerTopology::ExternalSupervisor),
        );

        self::assertSame(PreflightStatus::Pass, $finding->status);
    }

    private function context(string $host, WorkerTopology $topology): PreflightContext
    {
        $definition = DeploymentDefinition::build(host: $host, workerTopology: $topology);

        $manifest = new BuildManifest(
            buildId: 'build-1',
            gitSha: str_repeat('a', 40),
            imageRepository: 'ghcr.io/acme/app',
            imageDigest: 'sha256:' . str_repeat('ab', 32),
            targetArch: Arch::Arm64,
            environment: 'production',
            schemaFingerprint: SchemaFingerprint::empty(),
            createdAt: new \DateTimeImmutable(),
        );

        $state = new CurrentDeployState(
            activeColor: \Vortos\Deploy\Target\ActiveColor::Blue,
            currentDigest: 'sha256:' . str_repeat('ab', 32),
            appliedFingerprint: SchemaFingerprint::empty(),
        );

        return new PreflightContext($definition, $manifest, $state, new EnvironmentName('production'));
    }
}
