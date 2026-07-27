<?php

declare(strict_types=1);

namespace Vortos\Deploy\Plan;

use Vortos\Deploy\Exception\ContractInSameDeployException;

final readonly class PhaseGate
{
    /**
     * @throws ContractInSameDeployException if contract migrations are pending
     */
    /**
     * @param bool $forceContract explicit operator override; see the catch-22 below.
     */
    public function assertNoPendingContract(CurrentDeployState $state, bool $forceContract = false): void
    {
        if ($state->pendingContractMigrations === []) {
            return;
        }

        // THE CATCH-22 THIS ESCAPE HATCH EXISTS FOR
        //
        // The soak clock is measured from the ledger record written the first time a migration is
        // observed as PENDING — and a migration only becomes pending by being shipped. So on the
        // first deploy that carries a contract migration, elapsed is ~0 and deploysElapsed is 0, the
        // gate throws, and the deploy fails AFTER migrate/provision have already run on the target
        // but BEFORE cutover: the schema moved and the code did not. There was no way to start the
        // clock without burning a release.
        //
        // ManualReadiness::reason() has always told operators to "use --force-contract with 4-eyes
        // approval" — a flag that did not exist. The message was advice that could not be followed.
        //
        // This is deliberately an explicit override rather than a downgrade to a warning: skipping
        // the migration and deploying anyway is not currently expressible, because vortos:migrate
        // applies every pending migration and has no phase filter. Proceeding silently would apply
        // the contract migration regardless — precisely what the gate exists to prevent — so the
        // honest options are "refuse" or "the operator states they accept it", and this is the
        // latter.
        if ($forceContract) {
            return;
        }

        throw new ContractInSameDeployException($state->pendingContractMigrations);
    }
}
