<?php

declare(strict_types=1);

namespace Vortos\Deploy\Driver\Redis;

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

#[AsDriver('redis')]
final class RedisDeployStateStore implements
    DeployStateStoreInterface,
    CurrentReleaseStoreInterface,
    ContractSoakLedgerInterface,
    ManifestFreshnessStoreInterface
{
    private const KEY_PREFIX = 'vortos:deploy:run:';
    private const CURRENT_RELEASE_PREFIX = 'vortos:deploy:current_release:';
    private const CONTRACT_SOAK_PREFIX = 'vortos:deploy:contract_soak:';
    private const FRESHNESS_PREFIX = 'vortos:deploy:pull_agent_freshness:';

    public function __construct(
        private readonly \Redis $redis,
    ) {}

    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([
            'durable' => true,
            'concurrent_safe' => true,
            'queryable' => false,
        ]);
    }

    public function begin(DeployRun $run): void
    {
        $run->status = DeployStatus::Running;
        $this->persist($run);
        $this->redis->set($this->runIdKey($run->runId), $this->envPlanKey($run->env, $run->planHash));
    }

    public function checkpoint(string $runId, int $stepIndex, StepOutcome $outcome): void
    {
        $run = $this->findByRunId($runId);
        if ($run === null) {
            throw new \RuntimeException(sprintf('Deploy run not found: %s', $runId));
        }

        $run->addOutcome($outcome);
        $this->persist($run);
    }

    public function find(string $env, string $planHash): ?DeployRun
    {
        $key = $this->envPlanKey($env, $planHash);
        $data = $this->redis->get($key);

        if ($data === false) {
            return null;
        }

        return DeployRun::fromArray(json_decode($data, true, 512, \JSON_THROW_ON_ERROR));
    }

    public function complete(string $runId): void
    {
        $run = $this->findByRunId($runId);
        if ($run === null) {
            throw new \RuntimeException(sprintf('Deploy run not found: %s', $runId));
        }

        $run->status = DeployStatus::Completed;
        $this->persist($run);
    }

    public function fail(string $runId, string $reason): void
    {
        $run = $this->findByRunId($runId);
        if ($run === null) {
            throw new \RuntimeException(sprintf('Deploy run not found: %s', $runId));
        }

        $run->status = DeployStatus::Failed;
        $this->persist($run);
    }

    private function persist(DeployRun $run): void
    {
        $key = $this->envPlanKey($run->env, $run->planHash);
        $json = json_encode($run->toArray(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
        $this->redis->set($key, $json);
    }

    private function findByRunId(string $runId): ?DeployRun
    {
        $lookupKey = $this->runIdKey($runId);
        $envPlanKey = $this->redis->get($lookupKey);

        if ($envPlanKey === false) {
            return null;
        }

        $data = $this->redis->get($envPlanKey);

        if ($data === false) {
            return null;
        }

        return DeployRun::fromArray(json_decode($data, true, 512, \JSON_THROW_ON_ERROR));
    }

    private function envPlanKey(string $env, string $planHash): string
    {
        return self::KEY_PREFIX . $env . ':' . $planHash;
    }

    public function recordCurrentRelease(CurrentRelease $release): void
    {
        $key = self::CURRENT_RELEASE_PREFIX . $release->env;

        $this->redis->watch($key);
        $existing = $this->redis->get($key);

        if ($existing !== false) {
            $stored = CurrentRelease::fromArray(json_decode($existing, true, 512, \JSON_THROW_ON_ERROR));
            if ($release->generation <= $stored->generation) {
                $this->redis->unwatch();
                return;
            }
        }

        $json = json_encode($release->toArray(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
        $multi = $this->redis->multi();
        $multi->set($key, $json);
        $result = $multi->exec();

        if ($result === false) {
            return;
        }
    }

    public function currentRelease(string $env): ?CurrentRelease
    {
        $data = $this->redis->get(self::CURRENT_RELEASE_PREFIX . $env);

        if ($data === false) {
            return null;
        }

        return CurrentRelease::fromArray(json_decode($data, true, 512, \JSON_THROW_ON_ERROR));
    }

    private function runIdKey(string $runId): string
    {
        return self::KEY_PREFIX . 'id:' . $runId;
    }

    public function recordContractSoakObservation(string $env, string $migrationId, int $currentGeneration): ContractSoakRecord
    {
        $key = $this->contractSoakKey($env, $migrationId);
        $record = new ContractSoakRecord($migrationId, new \DateTimeImmutable(), $currentGeneration);
        $json = json_encode($record->toArray(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);

        $this->redis->setnx($key, $json);

        $existing = $this->contractSoakRecord($env, $migrationId);
        if ($existing === null) {
            throw new \RuntimeException(sprintf('Failed to record contract soak observation for "%s" in "%s".', $migrationId, $env));
        }

        return $existing;
    }

    public function contractSoakRecord(string $env, string $migrationId): ?ContractSoakRecord
    {
        $data = $this->redis->get($this->contractSoakKey($env, $migrationId));

        if ($data === false) {
            return null;
        }

        return ContractSoakRecord::fromArray(json_decode($data, true, 512, \JSON_THROW_ON_ERROR));
    }

    private function contractSoakKey(string $env, string $migrationId): string
    {
        return self::CONTRACT_SOAK_PREFIX . $env . ':' . hash('sha256', $migrationId);
    }

    public function loadFreshnessState(string $env): FreshnessSnapshot
    {
        $data = $this->redis->get(self::FRESHNESS_PREFIX . $env);

        if ($data === false) {
            return FreshnessSnapshot::empty($env);
        }

        return FreshnessSnapshot::fromArray(json_decode($data, true, 512, \JSON_THROW_ON_ERROR));
    }

    public function saveFreshnessState(string $env, FreshnessSnapshot $snapshot): void
    {
        $key = self::FRESHNESS_PREFIX . $env;

        $this->redis->watch($key);
        $existing = $this->redis->get($key);

        if ($existing !== false) {
            $stored = FreshnessSnapshot::fromArray(json_decode($existing, true, 512, \JSON_THROW_ON_ERROR));
            if ($snapshot->lastAppliedVersion < $stored->lastAppliedVersion) {
                $this->redis->unwatch();

                return;
            }
        }

        $json = json_encode($snapshot->toArray(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
        $multi = $this->redis->multi();
        $multi->set($key, $json);
        $multi->exec();
    }
}
