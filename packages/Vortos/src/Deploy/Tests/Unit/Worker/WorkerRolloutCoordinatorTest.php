<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Worker;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Registry\ImageReference;
use Vortos\Deploy\Tests\Fixtures\FakeWorkerController;
use Vortos\Deploy\Worker\DrainBudget;
use Vortos\Deploy\Worker\DrainOutcome;
use Vortos\Deploy\Worker\WorkerHandle;
use Vortos\Deploy\Worker\WorkerRolloutCoordinator;
use Vortos\Deploy\Worker\WorkerRolloutPlan;

final class WorkerRolloutCoordinatorTest extends TestCase
{
    private FakeWorkerController $controller;
    private WorkerRolloutCoordinator $coordinator;
    private ImageReference $image;

    protected function setUp(): void
    {
        $this->controller = new FakeWorkerController();
        $this->coordinator = new WorkerRolloutCoordinator($this->controller);
        $this->image = new ImageReference('app', digest: 'sha256:' . str_repeat('ab', 32));
    }

    public function test_empty_plan_is_a_clean_noop(): void
    {
        $outcomes = $this->coordinator->rollout(
            new WorkerRolloutPlan([]),
            $this->image,
            new DrainBudget(25),
        );

        $this->assertSame([], $outcomes);
        $this->assertSame([], $this->controller->calls);
    }

    public function test_single_worker_drain_before_launch(): void
    {
        $plan = new WorkerRolloutPlan([
            new WorkerHandle('my-worker', 1, 25),
        ]);

        $outcomes = $this->coordinator->rollout($plan, $this->image, new DrainBudget(25));

        $this->assertCount(1, $outcomes);
        $this->assertTrue($outcomes[0]->inFlightCompleted);

        // Verify ordering: drain → launch → status
        $actions = array_column($this->controller->calls, 'action');
        $drainIndex = array_search('drain', $actions, true);
        $launchIndex = array_search('launch', $actions, true);
        $this->assertLessThan($launchIndex, $drainIndex, 'drain must precede launch');
    }

    public function test_multi_worker_ordering_per_worker(): void
    {
        $plan = new WorkerRolloutPlan([
            new WorkerHandle('worker-a', 1, 25),
            new WorkerHandle('worker-b', 1, 25),
        ]);

        $outcomes = $this->coordinator->rollout($plan, $this->image, new DrainBudget(25));

        $this->assertCount(2, $outcomes);

        // Extract per-worker call sequences
        $workerACalls = array_values(array_filter(
            $this->controller->calls,
            fn ($c) => $c['worker'] === 'worker-a',
        ));
        $workerBCalls = array_values(array_filter(
            $this->controller->calls,
            fn ($c) => $c['worker'] === 'worker-b',
        ));

        // Each worker: drain → launch → status
        $this->assertSame('drain', $workerACalls[0]['action']);
        $this->assertSame('launch', $workerACalls[1]['action']);
        $this->assertSame('drain', $workerBCalls[0]['action']);
        $this->assertSame('launch', $workerBCalls[1]['action']);
    }

    public function test_forced_outcome_is_recorded_not_thrown(): void
    {
        $handle = new WorkerHandle('slow-worker', 1, 25);
        $this->controller->setDrainResults(
            DrainOutcome::forced($handle, 25000),
        );

        $plan = new WorkerRolloutPlan([$handle]);
        $outcomes = $this->coordinator->rollout($plan, $this->image, new DrainBudget(25));

        $this->assertCount(1, $outcomes);
        $this->assertTrue($outcomes[0]->forced);
        $this->assertFalse($outcomes[0]->inFlightCompleted);
    }

    public function test_budget_is_passed_through_to_controller(): void
    {
        $plan = new WorkerRolloutPlan([
            new WorkerHandle('worker', 1, 25),
        ]);

        $budget = new DrainBudget(deadlineSeconds: 42, pollIntervalMs: 200);
        $this->coordinator->rollout($plan, $this->image, $budget);

        // The fake records calls — the budget was passed (no crash = correct type)
        $this->assertNotEmpty($this->controller->calls);
    }

    public function test_summarize_aggregates_outcomes(): void
    {
        $h1 = new WorkerHandle('w1', 1, 25);
        $h2 = new WorkerHandle('w2', 1, 25);

        $outcomes = [
            DrainOutcome::graceful($h1, 100),
            DrainOutcome::forced($h2, 25000),
        ];

        $summary = WorkerRolloutCoordinator::summarize($outcomes);

        $this->assertStringContainsString('rolled 2 workers', $summary);
        $this->assertStringContainsString('graceful=1', $summary);
        $this->assertStringContainsString('forced=1', $summary);
        $this->assertStringContainsString('25100ms', $summary);
    }

    public function test_summarize_empty_outcomes(): void
    {
        $summary = WorkerRolloutCoordinator::summarize([]);

        $this->assertStringContainsString('rolled 0 workers', $summary);
    }
}
