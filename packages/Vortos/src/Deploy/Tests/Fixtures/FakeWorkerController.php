<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Fixtures;

use Vortos\Deploy\Registry\ImageReference;
use Vortos\Deploy\Worker\DrainBudget;
use Vortos\Deploy\Worker\DrainOutcome;
use Vortos\Deploy\Worker\WorkerControllerCapability;
use Vortos\Deploy\Worker\WorkerControllerInterface;
use Vortos\Deploy\Worker\WorkerHandle;
use Vortos\Deploy\Worker\WorkerRuntimeStatus;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

#[AsDriver('fake-worker-controller')]
final class FakeWorkerController implements WorkerControllerInterface
{
    /** @var list<array{action: string, worker: string}> */
    public array $calls = [];

    /** @var list<DrainOutcome> */
    private array $drainResults = [];
    private int $drainResultIndex = 0;

    private WorkerRuntimeStatus $nextStatus = WorkerRuntimeStatus::Running;

    private bool $shouldFailLaunch = false;

    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([
            WorkerControllerCapability::GracefulDrain->value => true,
            WorkerControllerCapability::DeadlineBounded->value => true,
            WorkerControllerCapability::RollingRecreate->value => true,
            WorkerControllerCapability::ForceKillOnOverrun->value => true,
            WorkerControllerCapability::RemoteControl->value => false,
            WorkerControllerCapability::ReadinessAfterLaunch->value => true,
        ]);
    }

    public function drain(WorkerHandle $worker, DrainBudget $budget): DrainOutcome
    {
        $this->calls[] = ['action' => 'drain', 'worker' => $worker->programName];

        if ($this->drainResults !== [] && isset($this->drainResults[$this->drainResultIndex])) {
            return $this->drainResults[$this->drainResultIndex++];
        }

        return DrainOutcome::graceful($worker, 150);
    }

    public function launch(WorkerHandle $worker, ImageReference $image): void
    {
        $this->calls[] = ['action' => 'launch', 'worker' => $worker->programName];

        if ($this->shouldFailLaunch) {
            throw new \RuntimeException('Fake: launch failed');
        }
    }

    public function status(WorkerHandle $worker): WorkerRuntimeStatus
    {
        $this->calls[] = ['action' => 'status', 'worker' => $worker->programName];

        return $this->nextStatus;
    }

    public function setDrainResults(DrainOutcome ...$outcomes): void
    {
        $this->drainResults = $outcomes;
        $this->drainResultIndex = 0;
    }

    public function setNextStatus(WorkerRuntimeStatus $status): void
    {
        $this->nextStatus = $status;
    }

    public function setFailLaunch(bool $fail): void
    {
        $this->shouldFailLaunch = $fail;
    }
}
