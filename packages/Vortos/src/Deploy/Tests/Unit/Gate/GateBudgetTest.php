<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Gate;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Gate\GateBudget;

final class GateBudgetTest extends TestCase
{
    public function test_defaults(): void
    {
        $budget = new GateBudget();

        $this->assertSame(60.0, $budget->timeout);
        $this->assertSame(2.0, $budget->interval);
        $this->assertSame(30, $budget->maxAttempts);
        $this->assertSame(5.0, $budget->perRequestTimeout);
    }

    public function test_custom_values(): void
    {
        $budget = new GateBudget(30.0, 1.0, 15, 3.0);

        $this->assertSame(30.0, $budget->timeout);
        $this->assertSame(1.0, $budget->interval);
        $this->assertSame(15, $budget->maxAttempts);
        $this->assertSame(3.0, $budget->perRequestTimeout);
    }

    public function test_rejects_zero_timeout(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new GateBudget(timeout: 0);
    }

    public function test_rejects_negative_interval(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new GateBudget(interval: -1.0);
    }

    public function test_rejects_zero_max_attempts(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new GateBudget(maxAttempts: 0);
    }

    public function test_rejects_negative_per_request_timeout(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new GateBudget(perRequestTimeout: -0.5);
    }
}
