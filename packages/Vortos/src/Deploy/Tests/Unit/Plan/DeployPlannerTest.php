<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Plan;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Definition\DeploymentDefinition;
use Vortos\Deploy\Definition\WorkerTopology;
use Vortos\Deploy\Plan\CurrentDeployState;
use Vortos\Deploy\Plan\DeployContext;
use Vortos\Deploy\Plan\DeployPlanner;
use Vortos\Deploy\Plan\PhaseKind;
use Vortos\Deploy\Strategy\BlueGreenStrategy;
use Vortos\Deploy\Strategy\CanaryStrategy;
use Vortos\Deploy\Strategy\DeployStrategyRegistry;
use Vortos\Deploy\Strategy\RecreateStrategy;
use Vortos\Deploy\Strategy\RollingStrategy;
use Vortos\Deploy\Target\ActiveColor;
use Vortos\Release\Manifest\Arch;
use Vortos\Release\Manifest\BuildManifest;
use Vortos\Release\Schema\SchemaFingerprint;

final class DeployPlannerTest extends TestCase
{
    private function createPlanner(): DeployPlanner
    {
        $registry = new DeployStrategyRegistry();
        $registry->register(new BlueGreenStrategy());
        $registry->register(new RollingStrategy());
        $registry->register(new RecreateStrategy());
        $registry->register(new CanaryStrategy());

        return new DeployPlanner($registry);
    }

    private function makeContext(
        string $strategy = 'blue-green',
        ActiveColor $color = ActiveColor::Blue,
        array $migrationIds = ['m001'],
        array $pendingContractMigrations = [],
        WorkerTopology $workerTopology = WorkerTopology::ExternalSupervisor,
    ): DeployContext {
        return new DeployContext(
            // External-supervisor topology exercises the full phase set (incl. RollWorkers); the
            // ride-color gating is covered in NotBlueGreenForWorkersTest/WorkerPhaseOrderingArchTest.
            definition: DeploymentDefinition::create()->strategy($strategy)->workerTopology($workerTopology)->build(),
            desiredManifest: new BuildManifest(
                buildId: 'build-1',
                gitSha: 'abc1234',
                imageRepository: 'ghcr.io/acme/app',
                imageDigest: 'sha256:' . str_repeat('a', 64),
                targetArch: Arch::Arm64,
                environment: 'prod',
                schemaFingerprint: new SchemaFingerprint($migrationIds),
                createdAt: new \DateTimeImmutable('2026-01-01'),
            ),
            currentState: new CurrentDeployState(
                activeColor: $color,
                currentDigest: 'sha256:' . str_repeat('b', 64),
                appliedFingerprint: new SchemaFingerprint(['m000']),
                pendingContractMigrations: $pendingContractMigrations,
            ),
        );
    }

    public function test_blue_green_produces_correct_phase_order(): void
    {
        $planner = $this->createPlanner();
        $plan = $planner->plan($this->makeContext());

        $kinds = array_map(fn ($p) => $p->kind, $plan->phases);
        $expected = [
            PhaseKind::ExpandMigrate,
            PhaseKind::RollWorkers,
            PhaseKind::ReconcileEdge,
            PhaseKind::StageColor,
            PhaseKind::HealthGate,
            PhaseKind::Smoke,
            PhaseKind::Cutover,
            PhaseKind::Promote,
            PhaseKind::Decommission,
        ];

        self::assertSame($expected, $kinds);
    }

    public function test_blue_green_decommissions_previous_color_as_final_phase(): void
    {
        $planner = $this->createPlanner();
        $plan = $planner->plan($this->makeContext(color: ActiveColor::Blue));

        $last = $plan->phases[array_key_last($plan->phases)];
        self::assertSame(PhaseKind::Decommission, $last->kind, 'Decommission must be the final phase.');

        // Step 0: post-cutover health re-check on the newly-promoted (green) color — the gate that makes
        // teardown safe. Step 1: tear down the previous (blue) color.
        self::assertSame(\Vortos\Deploy\Plan\StepAction::CheckHealth, $last->steps[0]->action);
        self::assertSame('green', $last->steps[0]->params['color']);

        self::assertSame(\Vortos\Deploy\Plan\StepAction::StopContainer, $last->steps[1]->action);
        self::assertSame('blue', $last->steps[1]->params['color'], 'Must tear down the OLD color, never the promoted one.');
    }

    public function test_blue_green_first_deploy_skips_stop_but_keeps_health_recheck(): void
    {
        $context = new DeployContext(
            definition: DeploymentDefinition::create()->build(),
            desiredManifest: new BuildManifest(
                buildId: 'build-1',
                gitSha: 'abc1234',
                imageRepository: 'ghcr.io/acme/app',
                imageDigest: 'sha256:' . str_repeat('a', 64),
                targetArch: Arch::Arm64,
                environment: 'prod',
                schemaFingerprint: new SchemaFingerprint(['m001']),
                createdAt: new \DateTimeImmutable('2026-01-01'),
            ),
            currentState: CurrentDeployState::firstDeploy(),
        );

        $plan = $this->createPlanner()->plan($context);

        $last = $plan->phases[array_key_last($plan->phases)];
        self::assertSame(PhaseKind::Decommission, $last->kind);
        // On a bootstrap deploy the previous color is None — there is nothing to reap, so only the
        // post-cutover health re-check is emitted (no no-op teardown of a nonexistent vortos-app-none).
        self::assertCount(1, $last->steps);
        self::assertSame(\Vortos\Deploy\Plan\StepAction::CheckHealth, $last->steps[0]->action);
    }

    public function test_blue_green_stages_opposite_color(): void
    {
        $planner = $this->createPlanner();
        $plan = $planner->plan($this->makeContext(color: ActiveColor::Blue));

        $stagePhase = null;
        foreach ($plan->phases as $phase) {
            if ($phase->kind === PhaseKind::StageColor) {
                $stagePhase = $phase;
                break;
            }
        }

        self::assertNotNull($stagePhase);
        self::assertSame('green', $stagePhase->steps[1]->params['color']);
    }

    public function test_blue_green_first_deploy_from_none(): void
    {
        $context = new DeployContext(
            definition: DeploymentDefinition::create()->build(),
            desiredManifest: new BuildManifest(
                buildId: 'build-1',
                gitSha: 'abc1234',
                imageRepository: 'ghcr.io/acme/app',
                imageDigest: 'sha256:' . str_repeat('a', 64),
                targetArch: Arch::Arm64,
                environment: 'prod',
                schemaFingerprint: new SchemaFingerprint(['m001']),
                createdAt: new \DateTimeImmutable('2026-01-01'),
            ),
            currentState: CurrentDeployState::firstDeploy(),
        );

        $plan = $this->createPlanner()->plan($context);

        self::assertTrue($plan->hasPhase(PhaseKind::StageColor));
        $stagePhase = null;
        foreach ($plan->phases as $phase) {
            if ($phase->kind === PhaseKind::StageColor) {
                $stagePhase = $phase;
            }
        }
        self::assertNotNull($stagePhase);
        self::assertSame('blue', $stagePhase->steps[1]->params['color']);
    }

    public function test_empty_migration_set_skips_expand_phase(): void
    {
        $context = new DeployContext(
            definition: DeploymentDefinition::create()->build(),
            desiredManifest: new BuildManifest(
                buildId: 'build-1',
                gitSha: 'abc1234',
                imageRepository: 'ghcr.io/acme/app',
                imageDigest: 'sha256:' . str_repeat('a', 64),
                targetArch: Arch::Arm64,
                environment: 'prod',
                schemaFingerprint: SchemaFingerprint::empty(),
                createdAt: new \DateTimeImmutable('2026-01-01'),
            ),
            currentState: CurrentDeployState::firstDeploy(),
        );

        $plan = $this->createPlanner()->plan($context);
        self::assertFalse($plan->hasPhase(PhaseKind::ExpandMigrate));
    }

    public function test_pending_contract_throws_at_plan_time(): void
    {
        $this->expectException(\Vortos\Deploy\Exception\ContractInSameDeployException::class);
        $this->expectExceptionMessageMatches('/m_drop_x/');

        $this->createPlanner()->plan($this->makeContext(pendingContractMigrations: ['m_drop_x']));
    }

    public function test_no_pending_contract_produces_valid_plan(): void
    {
        $plan = $this->createPlanner()->plan($this->makeContext(pendingContractMigrations: []));

        self::assertFalse($plan->hasPhase(PhaseKind::ContractGuard));
        self::assertTrue($plan->hasPhase(PhaseKind::Promote));
    }

    public function test_recreate_strategy_produces_correct_phases(): void
    {
        $plan = $this->createPlanner()->plan($this->makeContext(strategy: 'recreate'));

        $kinds = array_map(fn ($p) => $p->kind, $plan->phases);
        self::assertContains(PhaseKind::ExpandMigrate, $kinds);
        self::assertContains(PhaseKind::StageColor, $kinds);
        self::assertContains(PhaseKind::Promote, $kinds);
    }

    public function test_rolling_strategy_produces_correct_phases(): void
    {
        $plan = $this->createPlanner()->plan($this->makeContext(strategy: 'rolling'));

        $kinds = array_map(fn ($p) => $p->kind, $plan->phases);
        self::assertContains(PhaseKind::ExpandMigrate, $kinds);
        self::assertContains(PhaseKind::RollWorkers, $kinds);
        self::assertContains(PhaseKind::HealthGate, $kinds);
        self::assertContains(PhaseKind::Promote, $kinds);
    }

    public function test_canary_strategy_has_weighted_cutover_phases(): void
    {
        $plan = $this->createPlanner()->plan($this->makeContext(strategy: 'canary'));

        $cutoverPhases = array_filter($plan->phases, fn ($p) => $p->kind === PhaseKind::Cutover);
        self::assertGreaterThanOrEqual(4, count($cutoverPhases));
    }

    public function test_determinism_same_context_same_plan(): void
    {
        $planner = $this->createPlanner();
        $context = $this->makeContext();

        $hashes = [];
        for ($i = 0; $i < 100; $i++) {
            $plan = $planner->plan($context);
            $hashes[] = $plan->planHash->toString();
        }

        self::assertCount(1, array_unique($hashes), 'Plan hash must be identical for identical context across 100 runs.');
    }

    public function test_plan_carries_definition_hash(): void
    {
        $context = $this->makeContext();
        $plan = $this->createPlanner()->plan($context);

        self::assertSame($context->definition->definitionHash, $plan->definitionHash);
    }

    public function test_plan_hash_changes_with_different_manifest(): void
    {
        $planner = $this->createPlanner();

        $context1 = $this->makeContext();
        $context2 = new DeployContext(
            definition: DeploymentDefinition::create()->build(),
            desiredManifest: new BuildManifest(
                buildId: 'build-2',
                gitSha: 'def5678',
                imageRepository: 'ghcr.io/acme/app',
                imageDigest: 'sha256:' . str_repeat('c', 64),
                targetArch: Arch::Arm64,
                environment: 'prod',
                schemaFingerprint: new SchemaFingerprint(['m001', 'm002']),
                createdAt: new \DateTimeImmutable('2026-01-02'),
            ),
            currentState: $context1->currentState,
        );

        $p1 = $planner->plan($context1);
        $p2 = $planner->plan($context2);

        self::assertFalse($p1->planHash->equals($p2->planHash));
    }
}
