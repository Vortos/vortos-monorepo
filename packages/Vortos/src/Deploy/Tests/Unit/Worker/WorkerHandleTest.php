<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Worker;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Worker\WorkerHandle;

final class WorkerHandleTest extends TestCase
{
    public function test_valid_construction(): void
    {
        $handle = new WorkerHandle('my-worker', 2, 25);

        $this->assertSame('my-worker', $handle->programName);
        $this->assertSame(2, $handle->numprocs);
        $this->assertSame(25, $handle->drainDeadline);
    }

    public function test_empty_name_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new WorkerHandle('', 1, 25);
    }

    public function test_zero_numprocs_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new WorkerHandle('worker', 0, 25);
    }

    public function test_zero_drain_deadline_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new WorkerHandle('worker', 1, 0);
    }

    public function test_to_array(): void
    {
        $handle = new WorkerHandle('test-worker', 3, 30);
        $array = $handle->toArray();

        $this->assertSame([
            'program_name' => 'test-worker',
            'numprocs' => 3,
            'drain_deadline' => 30,
        ], $array);
    }
}
