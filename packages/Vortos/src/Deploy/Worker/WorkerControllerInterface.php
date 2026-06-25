<?php

declare(strict_types=1);

namespace Vortos\Deploy\Worker;

use Vortos\Deploy\Registry\ImageReference;
use Vortos\OpsKit\Driver\DriverInterface;

interface WorkerControllerInterface extends DriverInterface
{
    public function drain(WorkerHandle $worker, DrainBudget $budget): DrainOutcome;

    public function launch(WorkerHandle $worker, ImageReference $image): void;

    public function status(WorkerHandle $worker): WorkerRuntimeStatus;
}
