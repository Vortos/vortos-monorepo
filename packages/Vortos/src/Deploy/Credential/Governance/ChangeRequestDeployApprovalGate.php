<?php

declare(strict_types=1);

namespace Vortos\Deploy\Credential\Governance;

use Vortos\Deploy\Definition\EnvironmentName;
use Vortos\Deploy\Exception\CredentialGovernanceException;

final class ChangeRequestDeployApprovalGate implements DeployApprovalGateInterface
{
    /**
     * @param array<string, EnvironmentProtectionConfig> $protectedEnvironments env name → config
     * @param DeployChangeRequestStoreInterface          $store
     */
    public function __construct(
        private readonly array $protectedEnvironments,
        private readonly DeployChangeRequestStoreInterface $store,
    ) {}

    public function assertApproved(EnvironmentName $env, string $actorId): void
    {
        $config = $this->protectedEnvironments[$env->value] ?? null;
        if ($config === null || !$config->protected) {
            return;
        }

        $approved = $this->store->findApprovedForEnvironment($env->value);

        if ($approved === null) {
            throw CredentialGovernanceException::noApprovedChangeRequest($env->value);
        }

        if ($approved->requestedBy() === $actorId && $approved->approvedBy() === $actorId && $config->requiredApprovals > 0) {
            throw CredentialGovernanceException::selfApproval();
        }
    }
}
