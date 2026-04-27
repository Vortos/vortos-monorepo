<?php
declare(strict_types=1);

namespace Vortos\Tests\Auth\Quota;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\Quota\QuotaPeriod;
use Vortos\Auth\Quota\QuotaRule;

final class QuotaRuleTest extends TestCase
{
    public function test_creates_with_limit_and_period(): void
    {
        $rule = new QuotaRule(100, QuotaPeriod::Monthly);
        $this->assertSame(100, $rule->limit);
        $this->assertSame(QuotaPeriod::Monthly, $rule->period);
    }

    public function test_unlimited_has_max_int_limit(): void
    {
        $rule = QuotaRule::unlimited();
        $this->assertSame(PHP_INT_MAX, $rule->limit);
        $this->assertTrue($rule->isUnlimited());
    }

    public function test_normal_rule_is_not_unlimited(): void
    {
        $rule = new QuotaRule(100, QuotaPeriod::Monthly);
        $this->assertFalse($rule->isUnlimited());
    }
}
