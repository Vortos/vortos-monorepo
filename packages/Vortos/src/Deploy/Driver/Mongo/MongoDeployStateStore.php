<?php

declare(strict_types=1);

namespace Vortos\Deploy\Driver\Mongo;

use MongoDB\Collection;
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

#[AsDriver('mongo')]
final class MongoDeployStateStore implements
    DeployStateStoreInterface,
    CurrentReleaseStoreInterface,
    ContractSoakLedgerInterface,
    ManifestFreshnessStoreInterface
{
    public function __construct(
        private readonly Collection $collection,
    ) {}

    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([
            'durable' => true,
            'concurrent_safe' => true,
            'queryable' => true,
        ]);
    }

    public function begin(DeployRun $run): void
    {
        $run->status = DeployStatus::Running;
        $data = $run->toArray();
        $data['_id'] = $this->documentId($run->env, $run->planHash);

        $this->collection->replaceOne(
            ['_id' => $data['_id']],
            $data,
            ['upsert' => true],
        );
    }

    public function checkpoint(string $runId, int $stepIndex, StepOutcome $outcome): void
    {
        $run = $this->findByRunId($runId);
        if ($run === null) {
            throw new \RuntimeException(sprintf('Deploy run not found: %s', $runId));
        }

        $run->addOutcome($outcome);
        $data = $run->toArray();
        $data['_id'] = $this->documentId($run->env, $run->planHash);

        $this->collection->replaceOne(
            ['_id' => $data['_id']],
            $data,
        );
    }

    public function find(string $env, string $planHash): ?DeployRun
    {
        $doc = $this->collection->findOne(['_id' => $this->documentId($env, $planHash)]);

        if ($doc === null) {
            return null;
        }

        $data = (array) $doc;
        unset($data['_id']);

        return DeployRun::fromArray($data);
    }

    public function complete(string $runId): void
    {
        $run = $this->findByRunId($runId);
        if ($run === null) {
            throw new \RuntimeException(sprintf('Deploy run not found: %s', $runId));
        }

        $run->status = DeployStatus::Completed;
        $this->collection->updateOne(
            ['run_id' => $runId],
            ['$set' => ['status' => DeployStatus::Completed->value, 'updated_at' => $run->updatedAt->format(\DateTimeInterface::ATOM)]],
        );
    }

    public function fail(string $runId, string $reason): void
    {
        $run = $this->findByRunId($runId);
        if ($run === null) {
            throw new \RuntimeException(sprintf('Deploy run not found: %s', $runId));
        }

        $run->status = DeployStatus::Failed;
        $this->collection->updateOne(
            ['run_id' => $runId],
            ['$set' => ['status' => DeployStatus::Failed->value, 'updated_at' => $run->updatedAt->format(\DateTimeInterface::ATOM)]],
        );
    }

    private function findByRunId(string $runId): ?DeployRun
    {
        $doc = $this->collection->findOne(['run_id' => $runId]);

        if ($doc === null) {
            return null;
        }

        $data = (array) $doc;
        unset($data['_id']);

        return DeployRun::fromArray($data);
    }

    public function recordCurrentRelease(CurrentRelease $release): void
    {
        $docId = 'current_release:' . $release->env;
        $data = $release->toArray();

        $this->collection->updateOne(
            [
                '_id' => $docId,
                '$or' => [
                    ['generation' => ['$lt' => $release->generation]],
                    ['generation' => ['$exists' => false]],
                ],
            ],
            ['$set' => array_merge($data, ['_id' => $docId])],
            ['upsert' => true],
        );
    }

    public function currentRelease(string $env): ?CurrentRelease
    {
        $doc = $this->collection->findOne(['_id' => 'current_release:' . $env]);

        if ($doc === null) {
            return null;
        }

        $data = (array) $doc;
        unset($data['_id']);

        return CurrentRelease::fromArray($data);
    }

    private function documentId(string $env, string $planHash): string
    {
        return $env . ':' . $planHash;
    }

    public function recordContractSoakObservation(string $env, string $migrationId, int $currentGeneration): ContractSoakRecord
    {
        $docId = $this->contractSoakDocumentId($env, $migrationId);

        $this->collection->updateOne(
            ['_id' => $docId],
            [
                '$setOnInsert' => [
                    'migration_id' => $migrationId,
                    'first_observed_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                    'observed_at_generation' => $currentGeneration,
                ],
            ],
            ['upsert' => true],
        );

        $record = $this->contractSoakRecord($env, $migrationId);
        if ($record === null) {
            throw new \RuntimeException(sprintf('Failed to record contract soak observation for "%s" in "%s".', $migrationId, $env));
        }

        return $record;
    }

    public function contractSoakRecord(string $env, string $migrationId): ?ContractSoakRecord
    {
        $doc = $this->collection->findOne(['_id' => $this->contractSoakDocumentId($env, $migrationId)]);

        if ($doc === null) {
            return null;
        }

        $data = (array) $doc;
        unset($data['_id']);

        return ContractSoakRecord::fromArray($data);
    }

    private function contractSoakDocumentId(string $env, string $migrationId): string
    {
        return 'contract_soak:' . $env . ':' . hash('sha256', $migrationId);
    }

    public function loadFreshnessState(string $env): FreshnessSnapshot
    {
        $doc = $this->collection->findOne(['_id' => $this->freshnessDocumentId($env)]);

        if ($doc === null) {
            return FreshnessSnapshot::empty($env);
        }

        $data = (array) $doc;
        unset($data['_id']);

        return FreshnessSnapshot::fromArray($data);
    }

    public function saveFreshnessState(string $env, FreshnessSnapshot $snapshot): void
    {
        $docId = $this->freshnessDocumentId($env);
        $data = $snapshot->toArray();

        $this->collection->updateOne(
            [
                '_id' => $docId,
                '$or' => [
                    ['last_applied_version' => ['$lte' => $snapshot->lastAppliedVersion]],
                    ['last_applied_version' => ['$exists' => false]],
                ],
            ],
            ['$set' => array_merge($data, ['_id' => $docId])],
            ['upsert' => true],
        );
    }

    private function freshnessDocumentId(string $env): string
    {
        return 'pull_agent_freshness:' . $env;
    }
}
