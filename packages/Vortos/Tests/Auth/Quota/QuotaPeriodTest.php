<?php
declare(strict_types=1);

namespace Vortos\Tests\Auth\Quota;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\Quota\QuotaPeriod;

final class QuotaPeriodTest extends TestCase
{
    public function test_hourly_period_key_includes_hour(): void
    {
        $key = QuotaPeriod::Hourly->getPeriodKey();
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}-\d{2}$/', $key);
    }

    public function test_daily_period_key_is_date(): void
    {
        $key = QuotaPeriod::Daily->getPeriodKey();
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $key);
    }

    public function test_monthly_period_key_is_year_month(): void
    {
        $key = QuotaPeriod::Monthly->getPeriodKey();
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}$/', $key);
    }

    public function test_total_period_key_is_total(): void
    {
        $this->assertSame('total', QuotaPeriod::Total->getPeriodKey());
    }

    public function test_hourly_ttl_is_3600(): void
    {
        $this->assertSame(3600, QuotaPeriod::Hourly->getTtlSeconds());
    }

    public function test_daily_ttl_is_86400(): void
    {
        $this->assertSame(86400, QuotaPeriod::Daily->getTtlSeconds());
    }

    public function test_total_ttl_is_zero(): void
    {
        $this->assertSame(0, QuotaPeriod::Total->getTtlSeconds());
    }
}
