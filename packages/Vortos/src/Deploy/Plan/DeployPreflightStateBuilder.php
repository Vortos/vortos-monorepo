<?php

declare(strict_types=1);

namespace Vortos\Deploy\Plan;

use Vortos\Deploy\Contract\ContractReadinessInterface;
use Vortos\Deploy\Definition\DeploymentDefinition;
use Vortos\Deploy\Definition\EnvironmentName;
use Vortos\Deploy\State\ContractSoakLedgerInterface;
use Vortos\Deploy\State\CurrentReleaseStoreInterface;
use Vortos\Deploy\Target\ActiveColor;
use Vortos\Migration\Schema\MigrationPhase;
use Vortos\Migration\Schema\MigrationPhaseReaderInterface;
use Vortos\Release\Manifest\BuildManifest;
use Vortos\Release\Migration\AppliedMigrationSetReaderInterface;

final readonly class DeployPreflightStateBuilder
{
    public function __construct(
        private readonly AppliedMigrationSetReaderInterface $appliedReader,
        private readonly MigrationPhaseReaderInterface $phaseReader,
        private readonly ContractReadinessInterface $contractReadiness,
        private readonly ContractSoakLedgerInterface $soakLedger,
        private readonly CurrentReleaseStoreInterface $releaseStore,
    ) {}

    public function build(
        DeploymentDefinition $definition,
        BuildManifest $desired,
        ActiveColor $activeColor,
        string $currentDigest,
    ): CurrentDeployState {
        $applied = $this->appliedReader->currentApplied();
        $pending = $desired->schemaFingerprint->missingFrom($applied);

        if ($pending === []) {
            return new CurrentDeployState(
                activeColor: $activeColor,
                currentDigest: $currentDigest,
                appliedFingerprint: $applied,
                pendingContractMigrations: [],
            );
        }

        $env = new EnvironmentName($desired->environment);
        $phases = $this->phaseReader->phasesFor($pending);
        $contractIds = [];
        $currentRelease = $this->releaseStore->currentRelease($env->value);
        $currentGeneration = $currentRelease !== null ? $currentRelease->generation : 0;

        foreach ($phases as $id => $phase) {
            if ($phase !== MigrationPhase::Contract) {
                continue;
            }

            // Start (or read) the soak clock the moment a contract migration first
            // becomes pending, regardless of which readiness driver is configured —
            // so switching drivers later never loses the soak baseline.
            $this->soakLedger->recordContractSoakObservation($env->value, $id, $currentGeneration);

            if (!$this->contractReadiness->isCleared($id, $env)) {
                $contractIds[] = $id;
            }
        }

        return new CurrentDeployState(
            activeColor: $activeColor,
            currentDigest: $currentDigest,
            appliedFingerprint: $applied,
            pendingContractMigrations: $contractIds,
        );
    }
}
