<?php

declare(strict_types=1);

namespace Vortos\Deploy\Credential\Governance;

use Vortos\Deploy\Definition\EnvironmentName;

final class NullDeployApprovalGate implements DeployApprovalGateInterface
{
    public function assertApproved(EnvironmentName $env, string $actorId): void
    {
        // No governance — all environments pass through
    }
}
