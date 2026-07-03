<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Definition\DeploymentDefinition;
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
        $context = $this->makeContext();
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
        $context = $this->makeContext();
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
        $context = $this->makeContext(drainDeadline: 42);
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

    private function makeContext(int $drainDeadline = 25): DeployContext
    {
        $definition = DeploymentDefinition::build(
            workerDrainDeadlineSeconds: $drainDeadline,
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
