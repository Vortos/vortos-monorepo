<?php

declare(strict_types=1);

namespace Vortos\Deploy\Credential\Governance;

use Vortos\Deploy\Definition\EnvironmentName;
use Vortos\Deploy\Exception\CredentialGovernanceException;

interface DeployApprovalGateInterface
{
    /**
     * @throws CredentialGovernanceException if the environment is protected and no approved change request exists
     */
    public function assertApproved(EnvironmentName $env, string $actorId): void;
}
