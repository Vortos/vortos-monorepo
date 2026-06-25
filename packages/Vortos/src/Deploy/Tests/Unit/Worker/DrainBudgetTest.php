<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Worker;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Worker\DrainBudget;

final class DrainBudgetTest extends TestCase
{
    public function test_valid_construction(): void
    {
        $budget = new DrainBudget(deadlineSeconds: 25, pollIntervalMs: 500);

        $this->assertSame(25, $budget->deadlineSeconds);
        $this->assertSame(500, $budget->pollIntervalMs);
    }

    public function test_default_poll_interval(): void
    {
        $budget = new DrainBudget(deadlineSeconds: 10);

        $this->assertSame(500, $budget->pollIntervalMs);
    }

    public function test_deadline_too_low_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DrainBudget(deadlineSeconds: 0);
    }

    public function test_deadline_too_high_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DrainBudget(deadlineSeconds: 3601);
    }

    public function test_poll_interval_too_low_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DrainBudget(deadlineSeconds: 10, pollIntervalMs: 49);
    }

    public function test_poll_interval_too_high_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DrainBudget(deadlineSeconds: 10, pollIntervalMs: 5001);
    }

    public function test_boundary_values_valid(): void
    {
        $min = new DrainBudget(deadlineSeconds: 1, pollIntervalMs: 50);
        $this->assertSame(1, $min->deadlineSeconds);
        $this->assertSame(50, $min->pollIntervalMs);

        $max = new DrainBudget(deadlineSeconds: 3600, pollIntervalMs: 5000);
        $this->assertSame(3600, $max->deadlineSeconds);
        $this->assertSame(5000, $max->pollIntervalMs);
    }

    public function test_to_array_round_trips(): void
    {
        $budget = new DrainBudget(deadlineSeconds: 25, pollIntervalMs: 1000);
        $array = $budget->toArray();

        $this->assertSame(['deadline_seconds' => 25, 'poll_interval_ms' => 1000], $array);

        $restored = DrainBudget::fromArray($array);
        $this->assertSame($budget->deadlineSeconds, $restored->deadlineSeconds);
        $this->assertSame($budget->pollIntervalMs, $restored->pollIntervalMs);
    }
}
