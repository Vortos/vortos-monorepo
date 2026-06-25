<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Worker;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Worker\WorkerHandle;
use Vortos\Deploy\Worker\WorkerRolloutPlan;
use Vortos\Docker\Worker\WorkerProcessDefinition;
use Vortos\Docker\Worker\WorkerProcessRegistry;

final class WorkerRolloutPlanTest extends TestCase
{
    public function test_empty_plan(): void
    {
        $plan = new WorkerRolloutPlan([]);

        $this->assertTrue($plan->isEmpty());
        $this->assertSame(0, $plan->count());
        $this->assertSame([], $plan->toArray());
    }

    public function test_from_registry(): void
    {
        $registry = new WorkerProcessRegistry([
            new WorkerProcessDefinition('consumer-a', 'php vortos:consume a', 'Consumer A'),
            new WorkerProcessDefinition('consumer-b', 'php vortos:consume b', 'Consumer B', numprocs: 3, drainDeadline: 20),
        ]);

        $plan = WorkerRolloutPlan::fromRegistry($registry);

        $this->assertFalse($plan->isEmpty());
        $this->assertSame(2, $plan->count());

        $this->assertSame('consumer-a', $plan->handles[0]->programName);
        $this->assertSame(1, $plan->handles[0]->numprocs);
        $this->assertSame(25, $plan->handles[0]->drainDeadline);

        $this->assertSame('consumer-b', $plan->handles[1]->programName);
        $this->assertSame(3, $plan->handles[1]->numprocs);
        $this->assertSame(20, $plan->handles[1]->drainDeadline);
    }

    public function test_to_array(): void
    {
        $plan = new WorkerRolloutPlan([
            new WorkerHandle('w1', 1, 25),
            new WorkerHandle('w2', 2, 30),
        ]);

        $array = $plan->toArray();
        $this->assertCount(2, $array);
        $this->assertSame('w1', $array[0]['program_name']);
        $this->assertSame('w2', $array[1]['program_name']);
    }
}
