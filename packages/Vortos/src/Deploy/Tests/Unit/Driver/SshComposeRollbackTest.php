<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Driver;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Definition\EnvironmentName;
use Vortos\Deploy\Driver\SshCompose\SshComposeTarget;
use Vortos\Deploy\Driver\SshCompose\StepExecutor;
use Vortos\Deploy\Exception\RollbackRefusedException;
use Vortos\Deploy\Plan\DeployPlan;
use Vortos\Deploy\Plan\DeployPlanner;
use Vortos\Deploy\Plan\PhaseGate;
use Vortos\Deploy\Rollback\RollbackGuard;
use Vortos\Deploy\Strategy\BlueGreenStrategy;
use Vortos\Deploy\Strategy\DeployStrategyRegistry;
use Vortos\Deploy\Tests\Fixtures\FakeCommandRunner;
use Vortos\Deploy\Tests\Fixtures\FakeContainerRegistry;
use Vortos\Deploy\Tests\Fixtures\FakeDeployStateStore;
use Vortos\Deploy\Tests\Fixtures\FakeReadinessGate;
use Vortos\Deploy\Tests\Fixtures\FakeSmokeRunner;
use Vortos\Release\Manifest\Arch;
use Vortos\Release\Manifest\BuildManifest;
use Vortos\Release\Migration\AppliedMigrationSetReaderInterface;
use Vortos\Release\ReadModel\ManifestReadModelInterface;
use Vortos\Release\Schema\KnownMigrationSet;
use Vortos\Release\Schema\SchemaFingerprint;

final class SshComposeRollbackTest extends TestCase
{
    private function manifest(array $migrationIds): BuildManifest
    {
        return new BuildManifest(
            buildId: 'build-1',
            gitSha: 'abc1234',
            imageRepository: 'ghcr.io/acme/app',
            imageDigest: 'sha256:' . str_repeat('a', 64),
            targetArch: Arch::Arm64,
            environment: 'production',
            schemaFingerprint: new SchemaFingerprint($migrationIds),
            createdAt: new \DateTimeImmutable('2026-01-01'),
        );
    }

    private function createTarget(?RollbackGuard $guard = null): SshComposeTarget
    {
        $registry = new DeployStrategyRegistry();
        $registry->register(new BlueGreenStrategy());
        $planner = new DeployPlanner($registry, new PhaseGate());

        $stateStore = new FakeDeployStateStore();

        $executor = new StepExecutor(
            stateStore: $stateStore,
            registry: new FakeContainerRegistry(),
            readinessGate: new FakeReadinessGate(),
            smokeRunner: new FakeSmokeRunner(),
            composeFactory: new \Vortos\Deploy\Compose\ComposeProjectFactory(),
            localRunner: new FakeCommandRunner(),
        );

        return new SshComposeTarget(
            planner: $planner,
            executor: $executor,
            registry: new FakeContainerRegistry(),
            stateStore: $stateStore,
            releaseStore: $stateStore,
            rollbackGuard: $guard,
        );
    }

    public function test_rollback_refuses_illegal_target(): void
    {
        $appliedReader = $this->createMock(AppliedMigrationSetReaderInterface::class);
        $appliedReader->method('currentApplied')->willReturn(new SchemaFingerprint(['m001']));

        $readModel = $this->createMock(ManifestReadModelInterface::class);
        $readModel->method('knownMigrationSetForEnvironment')->willReturn(new KnownMigrationSet(['m001']));

        $rollbackGuard = new RollbackGuard($appliedReader, $readModel);
        $target = $this->createTarget($rollbackGuard);

        $plan = new DeployPlan(phases: [], definitionHash: 'def-hash');
        $illegalTarget = $this->manifest(['m001', 'm_missing']);

        $this->expectException(RollbackRefusedException::class);

        $target->rollback($plan, new EnvironmentName('production'), $illegalTarget);
    }

    public function test_rollback_succeeds_on_legal_target(): void
    {
        $appliedReader = $this->createMock(AppliedMigrationSetReaderInterface::class);
        $appliedReader->method('currentApplied')->willReturn(new SchemaFingerprint(['m001', 'm002']));

        $readModel = $this->createMock(ManifestReadModelInterface::class);
        $readModel->method('knownMigrationSetForEnvironment')->willReturn(new KnownMigrationSet(['m001', 'm002']));

        $rollbackGuard = new RollbackGuard($appliedReader, $readModel);
        $target = $this->createTarget($rollbackGuard);

        $plan = new DeployPlan(phases: [], definitionHash: 'def-hash');
        $legalTarget = $this->manifest(['m001']);

        $status = $target->rollback($plan, new EnvironmentName('production'), $legalTarget);

        self::assertSame('ok', $status->healthStatus);
    }

    public function test_rollback_without_guard_succeeds(): void
    {
        $target = $this->createTarget();

        $plan = new DeployPlan(phases: [], definitionHash: 'def-hash');

        $status = $target->rollback($plan, new EnvironmentName('production'), $this->manifest(['m001']));

        self::assertSame('ok', $status->healthStatus);
    }
}
