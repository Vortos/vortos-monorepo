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
use Vortos\Deploy\Strategy\DeployStrategyInterface;
use Vortos\Deploy\Strategy\RecreateStrategy;
use Vortos\Deploy\Strategy\RollingStrategy;
use Vortos\Deploy\Target\ActiveColor;
use Vortos\Release\Manifest\Arch;
use Vortos\Release\Manifest\BuildManifest;
use Vortos\Release\Schema\SchemaFingerprint;

/**
 * Behaviour-based guarantees for the shared {@see \Vortos\Deploy\Strategy\WorkerRolloutPhaseFactory}
 * (B20). Asserts on the actual phases() output — not the strategy source — so the guarantee survives
 * the RollWorkers phase being centralised in the factory.
 */
final class WorkerPhaseOrderingArchTest extends TestCase
{
    /**
     * @dataProvider strategyProvider
     */
    public function test_roll_workers_precedes_cutover_under_external_supervisor(string $strategyClass): void
    {
        $kinds = $this->phaseKinds($strategyClass, WorkerTopology::ExternalSupervisor);

        $rollIndex = array_search(PhaseKind::RollWorkers, $kinds, true);
        $this->assertIsInt($rollIndex, $strategyClass . ' must emit RollWorkers under external-supervisor.');

        $cutoverIndex = array_search(PhaseKind::Cutover, $kinds, true);
        if (is_int($cutoverIndex)) {
            $this->assertLessThan($cutoverIndex, $rollIndex, $strategyClass . ': RollWorkers must precede Cutover.');
        }
    }

    /**
     * @dataProvider strategyProvider
     */
    public function test_no_roll_workers_under_ride_color(string $strategyClass): void
    {
        $kinds = $this->phaseKinds($strategyClass, WorkerTopology::RideColor);

        $this->assertNotContains(
            PhaseKind::RollWorkers,
            $kinds,
            $strategyClass . ': ride-color topology must emit no RollWorkers phase.',
        );
    }

    /**
     * @dataProvider strategyProvider
     */
    public function test_worker_drain_step_has_no_color_param(string $strategyClass): void
    {
        $context = $this->makeContext(WorkerTopology::ExternalSupervisor);
        /** @var DeployStrategyInterface $strategy */
        $strategy = new $strategyClass();

        foreach ($strategy->phases($context) as $phase) {
            if ($phase->kind !== PhaseKind::RollWorkers) {
                continue;
            }
            foreach ($phase->steps as $step) {
                if ($step->action === StepAction::DrainWorker) {
                    $this->assertArrayNotHasKey(
                        'color',
                        $step->params,
                        $strategyClass . ': DrainWorker must not carry a color param (workers are rolling-recreate).',
                    );
                }
            }
        }
    }

    /** @return list<PhaseKind> */
    private function phaseKinds(string $strategyClass, WorkerTopology $topology): array
    {
        /** @var DeployStrategyInterface $strategy */
        $strategy = new $strategyClass();

        return array_map(
            static fn ($p): PhaseKind => $p->kind,
            $strategy->phases($this->makeContext($topology)),
        );
    }

    private function makeContext(WorkerTopology $topology): DeployContext
    {
        $definition = DeploymentDefinition::build(workerTopology: $topology);

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

    /** @return array<string, array{class-string}> */
    public static function strategyProvider(): array
    {
        return [
            'BlueGreenStrategy' => [BlueGreenStrategy::class],
            'RollingStrategy' => [RollingStrategy::class],
            'RecreateStrategy' => [RecreateStrategy::class],
            'CanaryStrategy' => [CanaryStrategy::class],
        ];
    }
}
