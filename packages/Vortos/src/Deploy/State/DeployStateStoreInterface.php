<?php

declare(strict_types=1);

namespace Vortos\Deploy\State;

use Vortos\OpsKit\Driver\DriverInterface;

interface DeployStateStoreInterface extends DriverInterface
{
    public function begin(DeployRun $run): void;

    public function checkpoint(string $runId, int $stepIndex, StepOutcome $outcome): void;

    public function find(string $env, string $planHash): ?DeployRun;

    public function complete(string $runId): void;

    public function fail(string $runId, string $reason): void;
}
