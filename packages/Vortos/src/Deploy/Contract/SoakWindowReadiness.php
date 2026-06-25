<?php

declare(strict_types=1);

namespace Vortos\Deploy\Contract;

use Vortos\Deploy\Definition\EnvironmentName;
use Vortos\Deploy\State\ContractSoakLedgerInterface;
use Vortos\Deploy\State\CurrentReleaseStoreInterface;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

#[AsDriver('soak-window')]
final class SoakWindowReadiness implements ContractReadinessInterface
{
    public function __construct(
        private readonly ContractSoakLedgerInterface $ledger,
        private readonly CurrentReleaseStoreInterface $releaseStore,
        private readonly int $requiredDeployCount = 2,
        private readonly int $soakDurationSeconds = 3600,
    ) {}

    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([
            'deploy-count-soak' => true,
            'time-window-soak' => true,
        ]);
    }

    public function isCleared(string $migrationId, EnvironmentName $env): bool
    {
        $record = $this->ledger->contractSoakRecord($env->value, $migrationId);
        if ($record === null) {
            // Never observed as pending yet — fail closed, nothing to measure soak against.
            return false;
        }

        $elapsedSeconds = (new \DateTimeImmutable())->getTimestamp() - $record->firstObservedAt->getTimestamp();
        if ($elapsedSeconds >= $this->soakDurationSeconds) {
            return true;
        }

        $currentRelease = $this->releaseStore->currentRelease($env->value);
        $currentGeneration = $currentRelease !== null ? $currentRelease->generation : $record->observedAtGeneration;
        $deploysElapsed = $currentGeneration - $record->observedAtGeneration;

        return $deploysElapsed >= $this->requiredDeployCount;
    }

    public function reason(string $migrationId): string
    {
        return sprintf(
            'Soak window not elapsed: requires %d successful deploys or %d seconds since expand promotion.',
            $this->requiredDeployCount,
            $this->soakDurationSeconds,
        );
    }
}
