<?php

declare(strict_types=1);

namespace Vortos\Deploy\Plan;

use Vortos\Deploy\Exception\ContractInSameDeployException;

final readonly class PhaseGate
{
    /**
     * @throws ContractInSameDeployException if contract migrations are pending
     */
    public function assertNoPendingContract(CurrentDeployState $state): void
    {
        if ($state->pendingContractMigrations !== []) {
            throw new ContractInSameDeployException($state->pendingContractMigrations);
        }
    }
}
