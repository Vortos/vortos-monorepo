<?php

declare(strict_types=1);

namespace Vortos\Deploy\Driver\Supervisor;

use Vortos\Deploy\Execution\CommandRunnerInterface;
use Vortos\Deploy\Execution\RemoteCommand;
use Vortos\Deploy\Execution\SshTransportInterface;
use Vortos\Deploy\Registry\ImageReference;
use Vortos\Deploy\Worker\DrainBudget;
use Vortos\Deploy\Worker\DrainOutcome;
use Vortos\Deploy\Worker\WorkerControllerCapability;
use Vortos\Deploy\Worker\WorkerControllerInterface;
use Vortos\Deploy\Worker\WorkerHandle;
use Vortos\Deploy\Worker\WorkerRuntimeStatus;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

#[AsDriver('supervisor')]
final class SupervisorWorkerController implements WorkerControllerInterface
{
    public function __construct(
        private readonly CommandRunnerInterface $localRunner,
        private readonly ?SshTransportInterface $sshTransport = null,
    ) {}

    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([
            WorkerControllerCapability::GracefulDrain->value => true,
            WorkerControllerCapability::DeadlineBounded->value => true,
            WorkerControllerCapability::RollingRecreate->value => true,
            WorkerControllerCapability::ForceKillOnOverrun->value => true,
            WorkerControllerCapability::RemoteControl->value => $this->sshTransport !== null,
            WorkerControllerCapability::ReadinessAfterLaunch->value => true,
        ]);
    }

    public function drain(WorkerHandle $worker, DrainBudget $budget): DrainOutcome
    {
        $startMs = (int) (hrtime(true) / 1_000_000);

        $argv = ['supervisorctl', 'stop', $worker->programName];
        $result = $this->runCommand($argv, (float) $budget->deadlineSeconds);

        $durationMs = (int) (hrtime(true) / 1_000_000) - $startMs;

        if ($result->isSuccess()) {
            return DrainOutcome::graceful($worker, $durationMs);
        }

        return DrainOutcome::forced($worker, $durationMs);
    }

    public function launch(WorkerHandle $worker, ImageReference $image): void
    {
        $argv = ['supervisorctl', 'start', $worker->programName];
        $result = $this->runCommand($argv);
        $result->throwOnFailure();
    }

    public function status(WorkerHandle $worker): WorkerRuntimeStatus
    {
        $argv = ['supervisorctl', 'status', $worker->programName];
        $result = $this->runCommand($argv);

        return self::parseStatus($result->stdout);
    }

    /** @param list<string> $argv */
    private function runCommand(array $argv, ?float $timeout = null): \Vortos\Deploy\Execution\CommandResult
    {
        if ($this->sshTransport !== null) {
            return $this->sshTransport->run(new RemoteCommand($argv));
        }

        return $this->localRunner->run($argv, timeout: $timeout);
    }

    private static function parseStatus(string $output): WorkerRuntimeStatus
    {
        $line = trim(strtok($output, "\n") ?: '');
        $upper = strtoupper($line);

        if (str_contains($upper, 'FATAL')) {
            return WorkerRuntimeStatus::Fatal;
        }
        if (str_contains($upper, 'RUNNING')) {
            return WorkerRuntimeStatus::Running;
        }
        if (str_contains($upper, 'STOPPED')) {
            return WorkerRuntimeStatus::Stopped;
        }
        if (str_contains($upper, 'STARTING')) {
            return WorkerRuntimeStatus::Starting;
        }
        if (str_contains($upper, 'STOPPING')) {
            return WorkerRuntimeStatus::Stopping;
        }
        if (str_contains($upper, 'EXITED')) {
            return WorkerRuntimeStatus::Exited;
        }

        return WorkerRuntimeStatus::Unknown;
    }
}
