<?php

declare(strict_types=1);

namespace Vortos\DeployK8s\Worker;

use Vortos\Deploy\Registry\ImageReference;
use Vortos\Deploy\Worker\DrainBudget;
use Vortos\Deploy\Worker\DrainOutcome;
use Vortos\Deploy\Worker\WorkerControllerInterface;
use Vortos\Deploy\Worker\WorkerHandle;
use Vortos\Deploy\Worker\WorkerRuntimeStatus;
use Vortos\DeployK8s\Api\KubeApiInterface;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

#[AsDriver('k8s')]
final class KubernetesWorkerController implements WorkerControllerInterface
{
    public function __construct(
        private readonly KubeApiInterface $kubeApi,
        private readonly string $namespace = 'default',
    ) {}

    public function capabilities(): CapabilityDescriptor
    {
        return KubernetesWorkerCapability::descriptor();
    }

    public function drain(WorkerHandle $worker, DrainBudget $budget): DrainOutcome
    {
        $startMs = (int) (hrtime(true) / 1_000_000);
        $deploymentName = 'worker-' . $worker->programName;

        $this->kubeApi->scale('Deployment', $deploymentName, $this->namespace, 0);

        $deadline = $budget->deadlineSeconds;
        $elapsed = 0;
        $pollMs = $budget->pollIntervalMs;
        $graceful = false;

        while ($elapsed < $deadline) {
            $status = $this->kubeApi->rolloutStatus('Deployment', $deploymentName, $this->namespace);
            if ($status->readyReplicas === 0) {
                $graceful = true;
                break;
            }
            usleep($pollMs * 1000);
            $elapsed += $pollMs / 1000;
        }

        $durationMs = (int) (hrtime(true) / 1_000_000) - $startMs;

        if ($graceful) {
            return DrainOutcome::graceful($worker, $durationMs);
        }

        return DrainOutcome::forced($worker, $durationMs);
    }

    public function launch(WorkerHandle $worker, ImageReference $image): void
    {
        $deploymentName = 'worker-' . $worker->programName;
        $this->kubeApi->scale('Deployment', $deploymentName, $this->namespace, $worker->numprocs);
    }

    public function status(WorkerHandle $worker): WorkerRuntimeStatus
    {
        $deploymentName = 'worker-' . $worker->programName;

        $status = $this->kubeApi->rolloutStatus('Deployment', $deploymentName, $this->namespace);

        if ($status->ready && $status->readyReplicas >= $status->desiredReplicas) {
            return WorkerRuntimeStatus::Running;
        }

        if ($status->desiredReplicas === 0) {
            return WorkerRuntimeStatus::Stopped;
        }

        if ($status->updatedReplicas > 0 && $status->readyReplicas === 0) {
            return WorkerRuntimeStatus::Starting;
        }

        if ($status->readyReplicas > 0 && $status->readyReplicas < $status->desiredReplicas) {
            return WorkerRuntimeStatus::Starting;
        }

        return WorkerRuntimeStatus::Unknown;
    }
}
