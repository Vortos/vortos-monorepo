<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Definition\DeploymentDefinition;
use Vortos\Deploy\Definition\WorkerTopology;
use Vortos\Deploy\Plan\CurrentDeployState;
use Vortos\Deploy\Plan\DeployContext;
use Vortos\Deploy\Plan\PhaseKind;
use Vortos\Deploy\Plan\StepAction;
use Vortos\Deploy\Strategy\BlueGreenStrategy;
use Vortos\Deploy\Strategy\CanaryStrategy;
use Vortos\Deploy\Target\ActiveColor;
use Vortos\Release\Manifest\Arch;
use Vortos\Release\Manifest\BuildManifest;
use Vortos\Release\Schema\SchemaFingerprint;

final class NotBlueGreenForWorkersTest extends TestCase
{
    public function test_blue_green_worker_step_carries_no_color(): void
    {
        $context = $this->makeContext(topology: WorkerTopology::ExternalSupervisor);
        $phases = (new BlueGreenStrategy())->phases($context);

        foreach ($phases as $phase) {
            if ($phase->kind !== PhaseKind::RollWorkers) {
                continue;
            }
            foreach ($phase->steps as $step) {
                if ($step->action === StepAction::DrainWorker) {
                    $this->assertArrayNotHasKey(
                        'color',
                        $step->params,
                        'DrainWorker step must not carry a color param.',
                    );
                }
                if ($step->action === StepAction::StartWorker) {
                    $this->assertArrayNotHasKey(
                        'color',
                        $step->params,
                        'StartWorker step must not carry a color param.',
                    );
                }
            }
        }
    }

    public function test_canary_worker_step_carries_no_color(): void
    {
        $context = $this->makeContext(topology: WorkerTopology::ExternalSupervisor);
        $phases = (new CanaryStrategy())->phases($context);

        foreach ($phases as $phase) {
            if ($phase->kind !== PhaseKind::RollWorkers) {
                continue;
            }
            foreach ($phase->steps as $step) {
                $this->assertArrayNotHasKey(
                    'color',
                    $step->params,
                    'Worker step must not carry a color param — workers are rolling-recreate.',
                );
            }
        }
    }

    public function test_blue_green_sources_deadline_from_definition(): void
    {
        $context = $this->makeContext(drainDeadline: 42, topology: WorkerTopology::ExternalSupervisor);
        $phases = (new BlueGreenStrategy())->phases($context);

        foreach ($phases as $phase) {
            if ($phase->kind !== PhaseKind::RollWorkers) {
                continue;
            }
            foreach ($phase->steps as $step) {
                if ($step->action === StepAction::DrainWorker) {
                    $this->assertSame(42, $step->params['deadline_seconds'] ?? null);
                }
            }
        }
    }

    /**
     * B20: with the default ride-color topology, no strategy emits a supervisorctl RollWorkers phase
     * (the phase drove `supervisorctl` in a container with no supervisord — the original exit-7).
     *
     * @dataProvider rideColorStrategyProvider
     */
    public function test_ride_color_topology_emits_no_roll_workers_phase(string $strategyClass): void
    {
        $context = $this->makeContext(topology: WorkerTopology::RideColor);
        /** @var \Vortos\Deploy\Strategy\DeployStrategyInterface $strategy */
        $strategy = new $strategyClass();

        $phases = $strategy->phases($context);
        $kinds = array_map(static fn ($p): PhaseKind => $p->kind, $phases);

        $this->assertNotContains(
            PhaseKind::RollWorkers,
            $kinds,
            $strategyClass . ': ride-color topology must not emit a supervisorctl RollWorkers phase.',
        );
    }

    /**
     * B20: external-supervisor topology emits RollWorkers, and it must still precede any Cutover.
     *
     * @dataProvider rideColorStrategyProvider
     */
    public function test_external_supervisor_topology_emits_roll_workers_before_cutover(string $strategyClass): void
    {
        $context = $this->makeContext(topology: WorkerTopology::ExternalSupervisor);
        /** @var \Vortos\Deploy\Strategy\DeployStrategyInterface $strategy */
        $strategy = new $strategyClass();

        $kinds = array_map(static fn ($p): PhaseKind => $p->kind, $strategy->phases($context));

        $rollIndex = array_search(PhaseKind::RollWorkers, $kinds, true);
        $this->assertIsInt($rollIndex, $strategyClass . ': external-supervisor topology must emit RollWorkers.');

        $cutoverIndex = array_search(PhaseKind::Cutover, $kinds, true);
        if (is_int($cutoverIndex)) {
            $this->assertLessThan(
                $cutoverIndex,
                $rollIndex,
                $strategyClass . ': RollWorkers must precede Cutover.',
            );
        }
    }

    /** @return array<string, array{class-string}> */
    public static function rideColorStrategyProvider(): array
    {
        return [
            'BlueGreenStrategy' => [BlueGreenStrategy::class],
            'CanaryStrategy' => [CanaryStrategy::class],
            'RollingStrategy' => [\Vortos\Deploy\Strategy\RollingStrategy::class],
            'RecreateStrategy' => [\Vortos\Deploy\Strategy\RecreateStrategy::class],
        ];
    }

    private function makeContext(int $drainDeadline = 25, WorkerTopology $topology = WorkerTopology::RideColor): DeployContext
    {
        $definition = DeploymentDefinition::build(
            workerDrainDeadlineSeconds: $drainDeadline,
            workerTopology: $topology,
        );

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
            activeColor: ActiveColor::Blue,
            currentDigest: 'sha256:' . str_repeat('ab', 32),
            appliedFingerprint: SchemaFingerprint::empty(),
        );

        return new DeployContext($definition, $manifest, $state);
    }
}
