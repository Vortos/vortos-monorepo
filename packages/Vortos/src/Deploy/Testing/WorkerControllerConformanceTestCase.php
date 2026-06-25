<?php

declare(strict_types=1);

namespace Vortos\Deploy\Testing;

use Vortos\Deploy\Registry\ImageReference;
use Vortos\Deploy\Worker\DrainBudget;
use Vortos\Deploy\Worker\DrainOutcome;
use Vortos\Deploy\Worker\WorkerControllerCapability;
use Vortos\Deploy\Worker\WorkerControllerInterface;
use Vortos\Deploy\Worker\WorkerHandle;
use Vortos\Deploy\Worker\WorkerRuntimeStatus;
use Vortos\OpsKit\Testing\ConformanceTestCase;

abstract class WorkerControllerConformanceTestCase extends ConformanceTestCase
{
    abstract protected function createController(): WorkerControllerInterface;

    protected function createDriver(): WorkerControllerInterface
    {
        return $this->createController();
    }

    final public function test_declares_worker_controller_capabilities(): void
    {
        $caps = $this->createController()->capabilities()->toArray()['capabilities'];
        $this->assertArrayHasKey(WorkerControllerCapability::GracefulDrain->value, $caps);
        $this->assertArrayHasKey(WorkerControllerCapability::DeadlineBounded->value, $caps);
        $this->assertArrayHasKey(WorkerControllerCapability::RollingRecreate->value, $caps);
        $this->assertArrayHasKey(WorkerControllerCapability::ForceKillOnOverrun->value, $caps);
        $this->assertArrayHasKey(WorkerControllerCapability::RemoteControl->value, $caps);
        $this->assertArrayHasKey(WorkerControllerCapability::ReadinessAfterLaunch->value, $caps);
    }

    final public function test_drain_returns_drain_outcome(): void
    {
        $controller = $this->createController();
        $handle = new WorkerHandle('test-worker', 1, 25);
        $budget = new DrainBudget(deadlineSeconds: 10);

        $outcome = $controller->drain($handle, $budget);
        $this->assertInstanceOf(DrainOutcome::class, $outcome);
    }

    final public function test_drain_outcome_never_both_graceful_and_forced(): void
    {
        $controller = $this->createController();
        $handle = new WorkerHandle('test-worker', 1, 25);
        $budget = new DrainBudget(deadlineSeconds: 10);

        $outcome = $controller->drain($handle, $budget);

        $this->assertFalse(
            $outcome->inFlightCompleted && $outcome->forced,
            'DrainOutcome must never be both inFlightCompleted and forced.',
        );
    }

    final public function test_status_returns_valid_runtime_status(): void
    {
        $controller = $this->createController();
        $handle = new WorkerHandle('test-worker', 1, 25);

        $status = $controller->status($handle);
        $this->assertInstanceOf(WorkerRuntimeStatus::class, $status);
    }

    final public function test_launch_does_not_throw_for_known_worker(): void
    {
        $controller = $this->createController();
        $handle = new WorkerHandle('test-worker', 1, 25);
        $image = new ImageReference('app', digest: 'sha256:' . str_repeat('ab', 32));

        $controller->launch($handle, $image);
        $this->addToAssertionCount(1);
    }
}
