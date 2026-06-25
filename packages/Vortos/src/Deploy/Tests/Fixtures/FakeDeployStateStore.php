<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Fixtures;

use Vortos\Deploy\PullAgent\FreshnessSnapshot;
use Vortos\Deploy\PullAgent\ManifestFreshnessStoreInterface;
use Vortos\Deploy\State\ContractSoakLedgerInterface;
use Vortos\Deploy\State\ContractSoakRecord;
use Vortos\Deploy\State\CurrentRelease;
use Vortos\Deploy\State\CurrentReleaseStoreInterface;
use Vortos\Deploy\State\DeployRun;
use Vortos\Deploy\State\DeployStateStoreInterface;
use Vortos\Deploy\State\DeployStatus;
use Vortos\Deploy\State\StepOutcome;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

#[AsDriver('fake-state-store')]
final class FakeDeployStateStore implements
    DeployStateStoreInterface,
    CurrentReleaseStoreInterface,
    ContractSoakLedgerInterface,
    ManifestFreshnessStoreInterface
{
    /** @var array<string, DeployRun> Keyed by "env:planHash" */
    private array $runs = [];

    /** @var array<string, string> runId → "env:planHash" */
    private array $runIdIndex = [];

    /** @var array<string, CurrentRelease> Keyed by env */
    private array $currentReleases = [];

    /** @var array<string, ContractSoakRecord> Keyed by "env:migrationId" */
    private array $contractSoakRecords = [];

    /** @var array<string, FreshnessSnapshot> Keyed by env */
    private array $freshnessSnapshots = [];

    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([
            'durable' => true,
            'concurrent_safe' => false,
            'queryable' => false,
        ]);
    }

    public function begin(DeployRun $run): void
    {
        $run->status = DeployStatus::Running;
        $key = $run->env . ':' . $run->planHash;
        $this->runs[$key] = $run;
        $this->runIdIndex[$run->runId] = $key;
    }

    public function checkpoint(string $runId, int $stepIndex, StepOutcome $outcome): void
    {
        $run = $this->findByRunId($runId);
        if ($run === null) {
            throw new \RuntimeException(sprintf('Deploy run not found: %s', $runId));
        }

        $run->addOutcome($outcome);
    }

    public function find(string $env, string $planHash): ?DeployRun
    {
        return $this->runs[$env . ':' . $planHash] ?? null;
    }

    public function complete(string $runId): void
    {
        $run = $this->findByRunId($runId);
        if ($run === null) {
            throw new \RuntimeException(sprintf('Deploy run not found: %s', $runId));
        }

        $run->status = DeployStatus::Completed;
    }

    public function fail(string $runId, string $reason): void
    {
        $run = $this->findByRunId($runId);
        if ($run === null) {
            throw new \RuntimeException(sprintf('Deploy run not found: %s', $runId));
        }

        $run->status = DeployStatus::Failed;
    }

    public function recordCurrentRelease(CurrentRelease $release): void
    {
        $existing = $this->currentReleases[$release->env] ?? null;
        if ($existing !== null && $release->generation <= $existing->generation) {
            return;
        }

        $this->currentReleases[$release->env] = $release;
    }

    public function currentRelease(string $env): ?CurrentRelease
    {
        return $this->currentReleases[$env] ?? null;
    }

    private function findByRunId(string $runId): ?DeployRun
    {
        $key = $this->runIdIndex[$runId] ?? null;
        if ($key === null) {
            return null;
        }

        return $this->runs[$key] ?? null;
    }

    public function recordContractSoakObservation(string $env, string $migrationId, int $currentGeneration): ContractSoakRecord
    {
        $key = $env . ':' . $migrationId;

        if (!isset($this->contractSoakRecords[$key])) {
            $this->contractSoakRecords[$key] = new ContractSoakRecord($migrationId, new \DateTimeImmutable(), $currentGeneration);
        }

        return $this->contractSoakRecords[$key];
    }

    public function contractSoakRecord(string $env, string $migrationId): ?ContractSoakRecord
    {
        return $this->contractSoakRecords[$env . ':' . $migrationId] ?? null;
    }

    public function loadFreshnessState(string $env): FreshnessSnapshot
    {
        return $this->freshnessSnapshots[$env] ?? FreshnessSnapshot::empty($env);
    }

    public function saveFreshnessState(string $env, FreshnessSnapshot $snapshot): void
    {
        $existing = $this->freshnessSnapshots[$env] ?? null;
        if ($existing !== null && $snapshot->lastAppliedVersion < $existing->lastAppliedVersion) {
            return;
        }

        $this->freshnessSnapshots[$env] = $snapshot;
    }
}
