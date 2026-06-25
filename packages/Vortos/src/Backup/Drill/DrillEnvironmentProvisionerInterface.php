<?php

declare(strict_types=1);

namespace Vortos\Backup\Drill;

use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\OpsKit\Driver\DriverInterface;

interface DrillEnvironmentProvisionerInterface extends DriverInterface
{
    public function provision(DatabaseEngine $engine): DrillEnvironment;

    public function teardown(DrillEnvironment $env): void;
}
