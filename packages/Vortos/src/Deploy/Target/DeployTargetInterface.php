<?php

declare(strict_types=1);

namespace Vortos\Deploy\Target;

use Vortos\Deploy\Definition\EnvironmentName;
use Vortos\Deploy\Plan\DeployContext;
use Vortos\Deploy\Plan\DeployPlan;
use Vortos\Deploy\Registry\ImageReference;
use Vortos\OpsKit\Driver\DriverInterface;

interface DeployTargetInterface extends DriverInterface
{
    public function plan(DeployContext $context): DeployPlan;

    public function push(ImageReference $image): ImageReference;

    public function migrate(DeployPlan $plan): void;

    public function release(DeployPlan $plan): TargetStatus;

    public function rollback(DeployPlan $plan): TargetStatus;

    public function status(EnvironmentName $env): TargetStatus;
}
