<?php

declare(strict_types=1);

namespace Vortos\Deploy\State;

/**
 * Tracks, per (env, migrationId), the moment a pending contract migration was first
 * observed and how many successful deploys (release generations) had occurred for that
 * env at that moment. {@see \Vortos\Deploy\Contract\SoakWindowReadiness} reads this to
 * answer "has N deploys or T duration elapsed since this contract migration first became
 * eligible to ship" without trusting a free-form CLI value.
 *
 * Composed onto the same driver classes as {@see CurrentReleaseStoreInterface} (separate,
 * focused port — same pattern, not folded into DeployStateStoreInterface).
 */
interface ContractSoakLedgerInterface
{
    /**
     * Idempotent: the first call for a given (env, migrationId) wins and fixes the
     * soak baseline; subsequent calls return the existing record unchanged.
     */
    public function recordContractSoakObservation(string $env, string $migrationId, int $currentGeneration): ContractSoakRecord;

    public function contractSoakRecord(string $env, string $migrationId): ?ContractSoakRecord;
}
