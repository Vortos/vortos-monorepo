<?php

declare(strict_types=1);

namespace Vortos\Docker\Tests;

use PHPUnit\Framework\TestCase;
use Vortos\Docker\Worker\WorkerProcessDefinition;
use Vortos\Docker\Worker\WorkerProcessRegistry;

final class WorkerProcessRegistryTest extends TestCase
{
    public function test_rejects_duplicate_worker_names(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate worker definition');

        new WorkerProcessRegistry([
            new WorkerProcessDefinition('outbox-relay', 'php bin/console relay', 'Relay.'),
            new WorkerProcessDefinition('outbox-relay', 'php bin/console relay', 'Relay.'),
        ]);
    }

    public function test_selects_named_workers(): void
    {
        $registry = new WorkerProcessRegistry([
            new WorkerProcessDefinition('a', 'cmd-a', 'A.'),
            new WorkerProcessDefinition('b', 'cmd-b', 'B.'),
        ]);

        $selected = $registry->selected(['b'])->all();

        $this->assertCount(1, $selected);
        $this->assertSame('b', $selected[0]->name);
    }

    public function test_max_drain_deadline_returns_max(): void
    {
        $registry = new WorkerProcessRegistry([
            new WorkerProcessDefinition('a', 'cmd-a', 'A.', drainDeadline: 10),
            new WorkerProcessDefinition('b', 'cmd-b', 'B.', drainDeadline: 40, stopwaitsecs: 50),
            new WorkerProcessDefinition('c', 'cmd-c', 'C.', drainDeadline: 25),
        ]);

        $this->assertSame(40, $registry->maxDrainDeadline());
    }

    public function test_max_drain_deadline_empty_returns_zero(): void
    {
        $registry = new WorkerProcessRegistry();

        $this->assertSame(0, $registry->maxDrainDeadline());
    }

    public function test_is_empty(): void
    {
        $empty = new WorkerProcessRegistry();
        $this->assertTrue($empty->isEmpty());

        $nonEmpty = new WorkerProcessRegistry([
            new WorkerProcessDefinition('a', 'cmd-a', 'A.'),
        ]);
        $this->assertFalse($nonEmpty->isEmpty());
    }
}
