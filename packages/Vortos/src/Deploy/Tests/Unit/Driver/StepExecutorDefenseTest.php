<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Driver;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Compose\ComposeProjectFactory;
use Vortos\Deploy\Runtime\RuntimeServiceSpec;
use Vortos\Deploy\Driver\SshCompose\StepExecutor;
use Vortos\Deploy\Exception\ContractInSameDeployException;
use Vortos\Deploy\Plan\DeployPhase;
use Vortos\Deploy\Plan\DeployPlan;
use Vortos\Deploy\Plan\DeployStep;
use Vortos\Deploy\Plan\PhaseKind;
use Vortos\Deploy\Plan\StepAction;
use Vortos\Deploy\Registry\ImageReference;
use Vortos\Deploy\State\DeployRun;
use Vortos\Deploy\State\DeployStatus;
use Vortos\Deploy\Tests\Fixtures\FakeCommandRunner;
use Vortos\Deploy\Tests\Fixtures\FakeContainerRegistry;
use Vortos\Deploy\Tests\Fixtures\FakeDeployStateStore;
use Vortos\Deploy\Tests\Fixtures\FakeReadinessGate;
use Vortos\Deploy\Tests\Fixtures\FakeSmokeRunner;
use Vortos\Migration\Schema\MigrationPhase;
use Vortos\Migration\Schema\MigrationPhaseReaderInterface;

final class StepExecutorDefenseTest extends TestCase
{
    private FakeDeployStateStore $stateStore;

    protected function setUp(): void
    {
        $this->stateStore = new FakeDeployStateStore();
    }

    private function createExecutor(
        ?MigrationPhaseReaderInterface $phaseReader = null,
    ): StepExecutor {
        return new StepExecutor(
            stateStore: $this->stateStore,
            registry: new FakeContainerRegistry(),
            readinessGate: new FakeReadinessGate(),
            smokeRunner: new FakeSmokeRunner(),
            composeFactory: new ComposeProjectFactory(new RuntimeServiceSpec()),
            localRunner: new FakeCommandRunner(),
            phaseReader: $phaseReader,
        );
    }

    private function makeImage(): ImageReference
    {
        return new ImageReference('repo/app', 'v1', 'sha256:' . str_repeat('ab', 32));
    }

    private function makeRun(string $planHash): DeployRun
    {
        $run = new DeployRun(
            runId: 'test-run',
            env: 'prod',
            planHash: $planHash,
            definitionHash: 'def-hash',
            desiredDigest: 'sha256:' . str_repeat('ab', 32),
            status: DeployStatus::Pending,
        );
        $this->stateStore->begin($run);

        return $run;
    }

    public function test_run_migrations_rejects_contract_ids_at_execution(): void
    {
        $phaseReader = $this->createMock(MigrationPhaseReaderInterface::class);
        $phaseReader->method('phasesFor')->willReturn([
            'mExpand' => MigrationPhase::Expand,
            'mContract' => MigrationPhase::Contract,
        ]);

        $executor = $this->createExecutor(phaseReader: $phaseReader);

        $plan = new DeployPlan(
            phases: [
                new DeployPhase(PhaseKind::ExpandMigrate, [
                    new DeployStep(
                        StepAction::RunMigrations,
                        'Run migrations',
                        ['fingerprint' => 'sha256:xxx', 'pending_ids' => 'mExpand,mContract'],
                    ),
                ]),
            ],
            definitionHash: 'def-hash',
        );

        $run = $this->makeRun($plan->planHash->toString());

        $this->expectException(ContractInSameDeployException::class);

        $executor->execute($plan, $run, $this->makeImage());
    }

    public function test_expand_only_migrations_pass_defense_in_depth(): void
    {
        $phaseReader = $this->createMock(MigrationPhaseReaderInterface::class);
        $phaseReader->method('phasesFor')->willReturn([
            'mExpand1' => MigrationPhase::Expand,
            'mExpand2' => MigrationPhase::Expand,
        ]);

        $executor = $this->createExecutor(phaseReader: $phaseReader);

        $plan = new DeployPlan(
            phases: [
                new DeployPhase(PhaseKind::ExpandMigrate, [
                    new DeployStep(
                        StepAction::RunMigrations,
                        'Run migrations',
                        ['fingerprint' => 'sha256:xxx', 'pending_ids' => 'mExpand1,mExpand2'],
                    ),
                ]),
            ],
            definitionHash: 'def-hash',
        );

        $run = $this->makeRun($plan->planHash->toString());

        $executor->execute($plan, $run, $this->makeImage());

        self::assertSame(1, $run->completedStepCount());
    }

    public function test_no_phase_reader_still_runs_migrations(): void
    {
        $executor = $this->createExecutor();

        $plan = new DeployPlan(
            phases: [
                new DeployPhase(PhaseKind::ExpandMigrate, [
                    new DeployStep(
                        StepAction::RunMigrations,
                        'Run migrations',
                        ['fingerprint' => 'sha256:xxx'],
                    ),
                ]),
            ],
            definitionHash: 'def-hash',
        );

        $run = $this->makeRun($plan->planHash->toString());

        $executor->execute($plan, $run, $this->makeImage());

        self::assertSame(1, $run->completedStepCount());
    }
}
