<?php

declare(strict_types=1);

namespace Vortos\Deploy\Contract;

use Vortos\Deploy\Definition\EnvironmentName;
use Vortos\OpsKit\Driver\DriverInterface;

interface ContractReadinessInterface extends DriverInterface
{
    public function isCleared(string $migrationId, EnvironmentName $env): bool;

    public function reason(string $migrationId): string;
}
