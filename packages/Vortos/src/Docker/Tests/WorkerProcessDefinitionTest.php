<?php

declare(strict_types=1);

namespace Vortos\Docker\Tests;

use PHPUnit\Framework\TestCase;
use Vortos\Docker\Worker\WorkerProcessDefinition;

final class WorkerProcessDefinitionTest extends TestCase
{
    public function test_valid_default_pair(): void
    {
        $def = new WorkerProcessDefinition('my-worker', 'php vortos:consume main', 'Main consumer');

        $this->assertSame(25, $def->drainDeadline);
        $this->assertSame(30, $def->stopwaitsecs);
    }

    public function test_stopwaitsecs_less_than_drain_deadline_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('stopwaitsecs');

        new WorkerProcessDefinition(
            name: 'bad-worker',
            command: 'php vortos:consume main',
            description: 'Bad pair',
            stopwaitsecs: 10,
            drainDeadline: 20,
        );
    }

    public function test_equal_stopwaitsecs_and_drain_deadline_is_valid(): void
    {
        $def = new WorkerProcessDefinition(
            name: 'exact-match',
            command: 'php vortos:consume main',
            description: 'Exact match',
            stopwaitsecs: 25,
            drainDeadline: 25,
        );

        $this->assertSame(25, $def->stopwaitsecs);
        $this->assertSame(25, $def->drainDeadline);
    }

    public function test_drain_deadline_zero_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('drainDeadline');

        new WorkerProcessDefinition(
            name: 'zero-drain',
            command: 'php vortos:consume main',
            description: 'Zero drain',
            drainDeadline: 0,
        );
    }

    public function test_managed_block_contains_drain_deadline_comment(): void
    {
        $def = new WorkerProcessDefinition(
            name: 'my-worker',
            command: 'php vortos:consume main',
            description: 'Test worker',
            drainDeadline: 20,
        );

        $block = $def->managedBlock();

        $this->assertStringContainsString('stopwaitsecs=30', $block);
        $this->assertStringContainsString('; drain_deadline=20s', $block);
    }

    public function test_managed_block_contains_both_values(): void
    {
        $def = new WorkerProcessDefinition(
            name: 'fast-worker',
            command: 'php vortos:consume fast',
            description: 'Fast consumer',
            stopwaitsecs: 15,
            drainDeadline: 10,
        );

        $block = $def->managedBlock();

        $this->assertStringContainsString('stopwaitsecs=15', $block);
        $this->assertStringContainsString('; drain_deadline=10s', $block);
    }

    public function test_custom_pair_valid(): void
    {
        $def = new WorkerProcessDefinition(
            name: 'custom-worker',
            command: 'php vortos:consume custom',
            description: 'Custom',
            stopwaitsecs: 60,
            drainDeadline: 45,
        );

        $this->assertSame(60, $def->stopwaitsecs);
        $this->assertSame(45, $def->drainDeadline);
    }
}
