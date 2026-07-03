<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Plan;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Contract\ContractReadinessInterface;
use Vortos\Deploy\Definition\DeploymentDefinition;
use Vortos\Deploy\Definition\EnvironmentName;
use Vortos\Deploy\Plan\DeployPreflightStateBuilder;
use Vortos\Deploy\Target\ActiveColor;
use Vortos\Deploy\Tests\Fixtures\FakeDeployStateStore;
use Vortos\Migration\Schema\MigrationPhase;
use Vortos\Migration\Schema\MigrationPhaseReaderInterface;
use Vortos\Release\Manifest\Arch;
use Vortos\Release\Manifest\BuildManifest;
use Vortos\Release\Migration\AppliedMigrationSetReaderInterface;
use Vortos\Release\Schema\SchemaFingerprint;

final class DeployPreflightStateBuilderTest extends TestCase
{
    private function manifest(array $migrationIds, string $env = 'prod'): BuildManifest
    {
        return new BuildManifest(
            buildId: 'build-1',
            gitSha: 'abc1234',
            imageRepository: 'ghcr.io/acme/app',
            imageDigest: 'sha256:' . str_repeat('a', 64),
            targetArch: Arch::Arm64,
            environment: $env,
            schemaFingerprint: new SchemaFingerprint($migrationIds),
            createdAt: new \DateTimeImmutable('2026-01-01'),
        );
    }

    public function test_no_pending_yields_empty_contract_list(): void
    {
        $applied = new SchemaFingerprint(['m001', 'm002']);
        $desired = $this->manifest(['m001', 'm002']);

        $appliedReader = $this->createMock(AppliedMigrationSetReaderInterface::class);
        $appliedReader->method('currentApplied')->willReturn($applied);

        $phaseReader = $this->createMock(MigrationPhaseReaderInterface::class);
        $contractReadiness = $this->createMock(ContractReadinessInterface::class);

        $stateStore = new FakeDeployStateStore();
        $builder = new DeployPreflightStateBuilder($appliedReader, $phaseReader, $contractReadiness, $stateStore, $stateStore);
        $state = $builder->build(
            DeploymentDefinition::create()->build(),
            $desired,
            ActiveColor::Blue,
            'sha256:old',
        );

        self::assertSame([], $state->pendingContractMigrations);
        self::assertFalse($state->pendingContract());
    }

    public function test_pending_expand_only_yields_empty_contract_list(): void
    {
        $applied = new SchemaFingerprint(['m001']);
        $desired = $this->manifest(['m001', 'm002']);

        $appliedReader = $this->createMock(AppliedMigrationSetReaderInterface::class);
        $appliedReader->method('currentApplied')->willReturn($applied);

        $phaseReader = $this->createMock(MigrationPhaseReaderInterface::class);
        $phaseReader->method('phasesFor')->willReturn(['m002' => MigrationPhase::Expand]);

        $contractReadiness = $this->createMock(ContractReadinessInterface::class);

        $stateStore = new FakeDeployStateStore();
        $builder = new DeployPreflightStateBuilder($appliedReader, $phaseReader, $contractReadiness, $stateStore, $stateStore);
        $state = $builder->build(
            DeploymentDefinition::create()->build(),
            $desired,
            ActiveColor::Blue,
            'sha256:old',
        );

        self::assertSame([], $state->pendingContractMigrations);
    }

    public function test_pending_contract_not_cleared_yields_contract_ids(): void
    {
        $applied = new SchemaFingerprint(['m001']);
        $desired = $this->manifest(['m001', 'm002', 'm003']);

        $appliedReader = $this->createMock(AppliedMigrationSetReaderInterface::class);
        $appliedReader->method('currentApplied')->willReturn($applied);

        $phaseReader = $this->createMock(MigrationPhaseReaderInterface::class);
        $phaseReader->method('phasesFor')->willReturn([
            'm002' => MigrationPhase::Expand,
            'm003' => MigrationPhase::Contract,
        ]);

        $contractReadiness = $this->createMock(ContractReadinessInterface::class);
        $contractReadiness->method('isCleared')->willReturn(false);

        $stateStore = new FakeDeployStateStore();
        $builder = new DeployPreflightStateBuilder($appliedReader, $phaseReader, $contractReadiness, $stateStore, $stateStore);
        $state = $builder->build(
            DeploymentDefinition::create()->build(),
            $desired,
            ActiveColor::Blue,
            'sha256:old',
        );

        self::assertSame(['m003'], $state->pendingContractMigrations);
        self::assertTrue($state->pendingContract());
    }

    public function test_pending_contract_cleared_yields_empty_list(): void
    {
        $applied = new SchemaFingerprint(['m001']);
        $desired = $this->manifest(['m001', 'm002']);

        $appliedReader = $this->createMock(AppliedMigrationSetReaderInterface::class);
        $appliedReader->method('currentApplied')->willReturn($applied);

        $phaseReader = $this->createMock(MigrationPhaseReaderInterface::class);
        $phaseReader->method('phasesFor')->willReturn([
            'm002' => MigrationPhase::Contract,
        ]);

        $contractReadiness = $this->createMock(ContractReadinessInterface::class);
        $contractReadiness->method('isCleared')->willReturn(true);

        $stateStore = new FakeDeployStateStore();
        $builder = new DeployPreflightStateBuilder($appliedReader, $phaseReader, $contractReadiness, $stateStore, $stateStore);
        $state = $builder->build(
            DeploymentDefinition::create()->build(),
            $desired,
            ActiveColor::Blue,
            'sha256:old',
        );

        self::assertSame([], $state->pendingContractMigrations);
    }

    public function test_pending_contract_records_soak_observation_once(): void
    {
        $applied = new SchemaFingerprint(['m001']);
        $desired = $this->manifest(['m001', 'm002']);

        $appliedReader = $this->createMock(AppliedMigrationSetReaderInterface::class);
        $appliedReader->method('currentApplied')->willReturn($applied);

        $phaseReader = $this->createMock(MigrationPhaseReaderInterface::class);
        $phaseReader->method('phasesFor')->willReturn(['m002' => MigrationPhase::Contract]);

        $contractReadiness = $this->createMock(ContractReadinessInterface::class);
        $contractReadiness->method('isCleared')->willReturn(false);

        $stateStore = new FakeDeployStateStore();
        $stateStore->recordCurrentRelease(new \Vortos\Deploy\State\CurrentRelease(
            env: 'prod',
            activeColor: ActiveColor::Blue,
            imageDigest: 'sha256:' . str_repeat('a', 64),
            buildId: 'build-0',
            planHash: 'sha256:plan',
            recordedAt: new \DateTimeImmutable(),
            generation: 5,
        ));

        $builder = new DeployPreflightStateBuilder($appliedReader, $phaseReader, $contractReadiness, $stateStore, $stateStore);
        $builder->build(DeploymentDefinition::create()->build(), $desired, ActiveColor::Blue, 'sha256:old');

        $record = $stateStore->contractSoakRecord('prod', 'm002');
        self::assertNotNull($record);
        self::assertSame(5, $record->observedAtGeneration);

        // A second preflight evaluation must not move the baseline.
        $stateStore->recordCurrentRelease(new \Vortos\Deploy\State\CurrentRelease(
            env: 'prod',
            activeColor: ActiveColor::Blue,
            imageDigest: 'sha256:' . str_repeat('a', 64),
            buildId: 'build-1',
            planHash: 'sha256:plan2',
            recordedAt: new \DateTimeImmutable(),
            generation: 9,
        ));
        $builder->build(DeploymentDefinition::create()->build(), $desired, ActiveColor::Blue, 'sha256:old');

        $record = $stateStore->contractSoakRecord('prod', 'm002');
        self::assertNotNull($record);
        self::assertSame(5, $record->observedAtGeneration);
    }
}
