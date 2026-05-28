<?php

declare(strict_types=1);

namespace Vortos\Tests\Docker;

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
}
