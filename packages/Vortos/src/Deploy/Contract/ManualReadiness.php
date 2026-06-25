<?php

declare(strict_types=1);

namespace Vortos\Deploy\Contract;

use Vortos\Deploy\Definition\EnvironmentName;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

#[AsDriver('manual')]
final class ManualReadiness implements ContractReadinessInterface
{
    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([
            'deploy-count-soak' => false,
            'time-window-soak' => false,
        ]);
    }

    public function isCleared(string $migrationId, EnvironmentName $env): bool
    {
        return false;
    }

    public function reason(string $migrationId): string
    {
        return 'Manual readiness: contract never auto-clears. Use --force-contract with 4-eyes approval.';
    }
}
