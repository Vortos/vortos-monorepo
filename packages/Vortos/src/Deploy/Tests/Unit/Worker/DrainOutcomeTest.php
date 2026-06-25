<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Worker;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Worker\DrainOutcome;
use Vortos\Deploy\Worker\WorkerHandle;

final class DrainOutcomeTest extends TestCase
{
    private function handle(): WorkerHandle
    {
        return new WorkerHandle('test-worker', 1, 25);
    }

    public function test_graceful_factory(): void
    {
        $outcome = DrainOutcome::graceful($this->handle(), 150, 1);

        $this->assertTrue($outcome->inFlightCompleted);
        $this->assertFalse($outcome->forced);
        $this->assertSame(150, $outcome->durationMs);
        $this->assertSame(1, $outcome->attempts);
    }

    public function test_forced_factory(): void
    {
        $outcome = DrainOutcome::forced($this->handle(), 25000, 3);

        $this->assertFalse($outcome->inFlightCompleted);
        $this->assertTrue($outcome->forced);
        $this->assertSame(25000, $outcome->durationMs);
    }

    public function test_noop_factory(): void
    {
        $outcome = DrainOutcome::noop($this->handle());

        $this->assertFalse($outcome->inFlightCompleted);
        $this->assertFalse($outcome->forced);
        $this->assertSame(0, $outcome->durationMs);
        $this->assertSame(0, $outcome->attempts);
    }

    public function test_never_both_graceful_and_forced(): void
    {
        $this->expectException(\LogicException::class);

        new \ReflectionClass(DrainOutcome::class);
        // The private constructor prevents direct creation — test via reflection
        $constructor = new \ReflectionMethod(DrainOutcome::class, '__construct');
        $constructor->setAccessible(true);
        $instance = (new \ReflectionClass(DrainOutcome::class))->newInstanceWithoutConstructor();
        $constructor->invoke($instance, $this->handle(), true, true, 100, 1);
    }

    public function test_to_array(): void
    {
        $outcome = DrainOutcome::graceful($this->handle(), 150);
        $array = $outcome->toArray();

        $this->assertSame(true, $array['in_flight_completed']);
        $this->assertSame(false, $array['forced']);
        $this->assertSame(150, $array['duration_ms']);
        $this->assertArrayHasKey('worker', $array);
        $this->assertSame('test-worker', $array['worker']['program_name']);
    }
}
