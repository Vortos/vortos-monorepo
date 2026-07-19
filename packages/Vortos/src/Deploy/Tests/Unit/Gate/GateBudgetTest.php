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

    public function test_for_timeout_derives_attempts_so_the_timeout_is_the_real_bound(): void
    {
        // A 180s budget must actually allow polling for ~180s. With the old fixed 30-attempt cap and
        // a 2s interval the gate gave up after ~60s — a slow cold start looked like a failure. Here
        // maxAttempts must comfortably cover timeout/interval so the wall-clock deadline wins.
        $budget = GateBudget::forTimeout(180.0);

        $this->assertSame(180.0, $budget->timeout);
        $this->assertGreaterThanOrEqual((int) (180.0 / $budget->interval), $budget->maxAttempts);
    }

    public function test_for_timeout_respects_a_custom_interval(): void
    {
        $budget = GateBudget::forTimeout(60.0, 1.0);

        $this->assertSame(60.0, $budget->timeout);
        $this->assertSame(1.0, $budget->interval);
        $this->assertGreaterThanOrEqual(60, $budget->maxAttempts);
    }
}
