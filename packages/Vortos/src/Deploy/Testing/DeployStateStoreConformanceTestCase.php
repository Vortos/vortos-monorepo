<?php

declare(strict_types=1);

namespace Vortos\Deploy\Testing;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Plan\StepAction;
use Vortos\Deploy\PullAgent\FreshnessSnapshot;
use Vortos\Deploy\PullAgent\ManifestFreshnessStoreInterface;
use Vortos\Deploy\State\ContractSoakLedgerInterface;
use Vortos\Deploy\State\CurrentRelease;
use Vortos\Deploy\State\CurrentReleaseStoreInterface;
use Vortos\Deploy\State\DeployRun;
use Vortos\Deploy\State\DeployStateStoreInterface;
use Vortos\Deploy\State\DeployStatus;
use Vortos\Deploy\State\StepOutcome;
use Vortos\Deploy\State\StepStatus;
use Vortos\Deploy\Target\ActiveColor;

abstract class DeployStateStoreConformanceTestCase extends TestCase
{
    abstract protected function createStore(): DeployStateStoreInterface;

    final public function test_begin_and_find(): void
    {
        $store = $this->createStore();
        $run = $this->makeRun();

        $store->begin($run);

        $found = $store->find($run->env, $run->planHash);
        $this->assertNotNull($found);
        $this->assertSame($run->runId, $found->runId);
        $this->assertSame(DeployStatus::Running, $found->status);
    }

    final public function test_find_returns_null_for_unknown(): void
    {
        $store = $this->createStore();
        $this->assertNull($store->find('nonexistent', 'hash'));
    }

    final public function test_checkpoint_persists_outcome(): void
    {
        $store = $this->createStore();
        $run = $this->makeRun();
        $store->begin($run);

        $outcome = new StepOutcome(0, StepAction::PullImage, StepStatus::Success, 'pulled');
        $store->checkpoint($run->runId, 0, $outcome);

        $found = $store->find($run->env, $run->planHash);
        $this->assertNotNull($found);
        $this->assertTrue($found->isStepCompleted(0));
    }

    final public function test_complete_sets_status(): void
    {
        $store = $this->createStore();
        $run = $this->makeRun();
        $store->begin($run);
        $store->complete($run->runId);

        $found = $store->find($run->env, $run->planHash);
        $this->assertNotNull($found);
        $this->assertSame(DeployStatus::Completed, $found->status);
    }

    final public function test_fail_sets_status(): void
    {
        $store = $this->createStore();
        $run = $this->makeRun();
        $store->begin($run);
        $store->fail($run->runId, 'timeout');

        $found = $store->find($run->env, $run->planHash);
        $this->assertNotNull($found);
        $this->assertSame(DeployStatus::Failed, $found->status);
    }

    final public function test_multiple_checkpoints(): void
    {
        $store = $this->createStore();
        $run = $this->makeRun();
        $store->begin($run);

        $store->checkpoint($run->runId, 0, new StepOutcome(0, StepAction::PullImage, StepStatus::Success));
        $store->checkpoint($run->runId, 1, new StepOutcome(1, StepAction::StartContainer, StepStatus::Success));
        $store->checkpoint($run->runId, 2, new StepOutcome(2, StepAction::CheckHealth, StepStatus::Success));

        $found = $store->find($run->env, $run->planHash);
        $this->assertNotNull($found);
        $this->assertTrue($found->isStepCompleted(0));
        $this->assertTrue($found->isStepCompleted(1));
        $this->assertTrue($found->isStepCompleted(2));
        $this->assertFalse($found->isStepCompleted(3));
        $this->assertSame(3, $found->completedStepCount());
    }

    final public function test_current_release_round_trips(): void
    {
        $store = $this->createStore();
        if (!$store instanceof CurrentReleaseStoreInterface) {
            $this->markTestSkipped('Store does not implement CurrentReleaseStoreInterface.');
        }

        $release = $this->makeRelease('production', ActiveColor::Blue, 1);
        $store->recordCurrentRelease($release);

        $found = $store->currentRelease('production');
        $this->assertNotNull($found);
        $this->assertSame('production', $found->env);
        $this->assertSame(ActiveColor::Blue, $found->activeColor);
        $this->assertSame($release->imageDigest, $found->imageDigest);
        $this->assertSame($release->buildId, $found->buildId);
        $this->assertSame($release->planHash, $found->planHash);
        $this->assertSame(1, $found->generation);
    }

    final public function test_current_release_null_when_absent(): void
    {
        $store = $this->createStore();
        if (!$store instanceof CurrentReleaseStoreInterface) {
            $this->markTestSkipped('Store does not implement CurrentReleaseStoreInterface.');
        }

        $this->assertNull($store->currentRelease('nonexistent-env'));
    }

    final public function test_current_release_last_write_wins_on_higher_generation(): void
    {
        $store = $this->createStore();
        if (!$store instanceof CurrentReleaseStoreInterface) {
            $this->markTestSkipped('Store does not implement CurrentReleaseStoreInterface.');
        }

        $store->recordCurrentRelease($this->makeRelease('production', ActiveColor::Blue, 1));
        $store->recordCurrentRelease($this->makeRelease('production', ActiveColor::Green, 2));

        $found = $store->currentRelease('production');
        $this->assertNotNull($found);
        $this->assertSame(ActiveColor::Green, $found->activeColor);
        $this->assertSame(2, $found->generation);
    }

    final public function test_current_release_rejects_stale_generation(): void
    {
        $store = $this->createStore();
        if (!$store instanceof CurrentReleaseStoreInterface) {
            $this->markTestSkipped('Store does not implement CurrentReleaseStoreInterface.');
        }

        $store->recordCurrentRelease($this->makeRelease('production', ActiveColor::Green, 5));
        $store->recordCurrentRelease($this->makeRelease('production', ActiveColor::Blue, 3));
        $store->recordCurrentRelease($this->makeRelease('production', ActiveColor::Blue, 5));

        $found = $store->currentRelease('production');
        $this->assertNotNull($found);
        $this->assertSame(ActiveColor::Green, $found->activeColor);
        $this->assertSame(5, $found->generation);
    }

    final public function test_current_release_isolated_per_env(): void
    {
        $store = $this->createStore();
        if (!$store instanceof CurrentReleaseStoreInterface) {
            $this->markTestSkipped('Store does not implement CurrentReleaseStoreInterface.');
        }

        $store->recordCurrentRelease($this->makeRelease('production', ActiveColor::Blue, 1));
        $store->recordCurrentRelease($this->makeRelease('staging', ActiveColor::Green, 1));

        $prod = $store->currentRelease('production');
        $staging = $store->currentRelease('staging');

        $this->assertNotNull($prod);
        $this->assertNotNull($staging);
        $this->assertSame(ActiveColor::Blue, $prod->activeColor);
        $this->assertSame(ActiveColor::Green, $staging->activeColor);
    }

    final public function test_contract_soak_observation_is_recorded(): void
    {
        $store = $this->createStore();
        if (!$store instanceof ContractSoakLedgerInterface) {
            $this->markTestSkipped('Store does not implement ContractSoakLedgerInterface.');
        }

        $record = $store->recordContractSoakObservation('production', 'm_drop_x', 3);

        $this->assertSame('m_drop_x', $record->migrationId);
        $this->assertSame(3, $record->observedAtGeneration);

        $found = $store->contractSoakRecord('production', 'm_drop_x');
        $this->assertNotNull($found);
        $this->assertSame(3, $found->observedAtGeneration);
    }

    final public function test_contract_soak_record_null_when_absent(): void
    {
        $store = $this->createStore();
        if (!$store instanceof ContractSoakLedgerInterface) {
            $this->markTestSkipped('Store does not implement ContractSoakLedgerInterface.');
        }

        $this->assertNull($store->contractSoakRecord('production', 'unobserved_migration'));
    }

    final public function test_contract_soak_observation_is_idempotent(): void
    {
        $store = $this->createStore();
        if (!$store instanceof ContractSoakLedgerInterface) {
            $this->markTestSkipped('Store does not implement ContractSoakLedgerInterface.');
        }

        $first = $store->recordContractSoakObservation('production', 'm_drop_x', 3);
        $second = $store->recordContractSoakObservation('production', 'm_drop_x', 9);

        $this->assertSame($first->observedAtGeneration, $second->observedAtGeneration);
        $this->assertSame($first->firstObservedAt->format(\DateTimeInterface::ATOM), $second->firstObservedAt->format(\DateTimeInterface::ATOM));

        $found = $store->contractSoakRecord('production', 'm_drop_x');
        $this->assertNotNull($found);
        $this->assertSame(3, $found->observedAtGeneration);
    }

    final public function test_contract_soak_isolated_per_env(): void
    {
        $store = $this->createStore();
        if (!$store instanceof ContractSoakLedgerInterface) {
            $this->markTestSkipped('Store does not implement ContractSoakLedgerInterface.');
        }

        $store->recordContractSoakObservation('production', 'm_drop_x', 3);
        $store->recordContractSoakObservation('staging', 'm_drop_x', 7);

        $prod = $store->contractSoakRecord('production', 'm_drop_x');
        $staging = $store->contractSoakRecord('staging', 'm_drop_x');

        $this->assertNotNull($prod);
        $this->assertNotNull($staging);
        $this->assertSame(3, $prod->observedAtGeneration);
        $this->assertSame(7, $staging->observedAtGeneration);
    }

    final public function test_contract_soak_isolated_per_migration_id(): void
    {
        $store = $this->createStore();
        if (!$store instanceof ContractSoakLedgerInterface) {
            $this->markTestSkipped('Store does not implement ContractSoakLedgerInterface.');
        }

        $store->recordContractSoakObservation('production', 'm_drop_x', 3);
        $store->recordContractSoakObservation('production', 'm_drop_y', 8);

        $x = $store->contractSoakRecord('production', 'm_drop_x');
        $y = $store->contractSoakRecord('production', 'm_drop_y');

        $this->assertNotNull($x);
        $this->assertNotNull($y);
        $this->assertSame(3, $x->observedAtGeneration);
        $this->assertSame(8, $y->observedAtGeneration);
    }

    final public function test_freshness_state_round_trips(): void
    {
        $store = $this->createStore();
        if (!$store instanceof ManifestFreshnessStoreInterface) {
            $this->markTestSkipped('Store does not implement ManifestFreshnessStoreInterface.');
        }

        $now = new \DateTimeImmutable();
        $snapshot = new FreshnessSnapshot('production', 3, ['nonce-a' => $now, 'nonce-b' => $now]);
        $store->saveFreshnessState('production', $snapshot);

        $found = $store->loadFreshnessState('production');
        $this->assertSame(3, $found->lastAppliedVersion);
        $this->assertArrayHasKey('nonce-a', $found->seenNonces);
        $this->assertArrayHasKey('nonce-b', $found->seenNonces);
    }

    final public function test_freshness_state_empty_when_absent(): void
    {
        $store = $this->createStore();
        if (!$store instanceof ManifestFreshnessStoreInterface) {
            $this->markTestSkipped('Store does not implement ManifestFreshnessStoreInterface.');
        }

        $found = $store->loadFreshnessState('never-seen-env');
        $this->assertSame(0, $found->lastAppliedVersion);
        $this->assertSame([], $found->seenNonces);
    }

    final public function test_freshness_state_rejects_version_regression(): void
    {
        $store = $this->createStore();
        if (!$store instanceof ManifestFreshnessStoreInterface) {
            $this->markTestSkipped('Store does not implement ManifestFreshnessStoreInterface.');
        }

        $now = new \DateTimeImmutable();
        $store->saveFreshnessState('production', new FreshnessSnapshot('production', 5, ['n5' => $now]));
        $store->saveFreshnessState('production', new FreshnessSnapshot('production', 2, ['n2' => $now]));

        $found = $store->loadFreshnessState('production');
        $this->assertSame(5, $found->lastAppliedVersion);
    }

    final public function test_freshness_state_isolated_per_env(): void
    {
        $store = $this->createStore();
        if (!$store instanceof ManifestFreshnessStoreInterface) {
            $this->markTestSkipped('Store does not implement ManifestFreshnessStoreInterface.');
        }

        $now = new \DateTimeImmutable();
        $store->saveFreshnessState('production', new FreshnessSnapshot('production', 4, ['np' => $now]));
        $store->saveFreshnessState('staging', new FreshnessSnapshot('staging', 1, ['ns' => $now]));

        $prod = $store->loadFreshnessState('production');
        $staging = $store->loadFreshnessState('staging');

        $this->assertSame(4, $prod->lastAppliedVersion);
        $this->assertSame(1, $staging->lastAppliedVersion);
    }

    private function makeRelease(string $env, ActiveColor $color, int $generation): CurrentRelease
    {
        return new CurrentRelease(
            env: $env,
            activeColor: $color,
            imageDigest: 'sha256:' . str_repeat('ab', 32),
            buildId: 'build-' . bin2hex(random_bytes(4)),
            planHash: 'sha256:' . hash('sha256', 'test-plan'),
            recordedAt: new \DateTimeImmutable(),
            generation: $generation,
        );
    }

    private function makeRun(): DeployRun
    {
        return new DeployRun(
            runId: 'test-run-' . bin2hex(random_bytes(4)),
            env: 'production',
            planHash: 'sha256:' . hash('sha256', 'test-plan-' . bin2hex(random_bytes(4))),
            definitionHash: 'sha256:def123',
            desiredDigest: 'sha256:' . str_repeat('ab', 32),
        );
    }
}
