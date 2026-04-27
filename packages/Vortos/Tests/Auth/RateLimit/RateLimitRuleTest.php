<?php
declare(strict_types=1);

namespace Vortos\Tests\Auth\RateLimit;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\RateLimit\RateLimitRule;

final class RateLimitRuleTest extends TestCase
{
    public function test_creates_with_limit_and_window(): void
    {
        $rule = new RateLimitRule(100, 60);
        $this->assertSame(100, $rule->limit);
        $this->assertSame(60, $rule->windowSeconds);
    }

    public function test_unlimited_has_max_int_limit(): void
    {
        $rule = RateLimitRule::unlimited();
        $this->assertSame(PHP_INT_MAX, $rule->limit);
        $this->assertTrue($rule->isUnlimited());
    }

    public function test_normal_rule_is_not_unlimited(): void
    {
        $rule = new RateLimitRule(100, 60);
        $this->assertFalse($rule->isUnlimited());
    }
}
