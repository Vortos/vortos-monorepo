<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\Runtime;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Vortos\Backup\Runtime\CronDueEvaluator;

final class CronDueEvaluatorTest extends TestCase
{
    private CronDueEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new CronDueEvaluator();
    }

    public function test_every_six_hours_matches_only_on_the_hour_marks(): void
    {
        $cron = '0 */6 * * *';
        $this->assertTrue($this->evaluator->isDue($cron, $this->at('2024-01-01 06:00:00')));
        $this->assertTrue($this->evaluator->isDue($cron, $this->at('2024-01-01 12:00:00')));
        $this->assertFalse($this->evaluator->isDue($cron, $this->at('2024-01-01 06:30:00')));
        $this->assertFalse($this->evaluator->isDue($cron, $this->at('2024-01-01 05:00:00')));
    }

    public function test_daily_at_three_am(): void
    {
        $cron = '0 3 * * *';
        $this->assertTrue($this->evaluator->isDue($cron, $this->at('2024-06-15 03:00:00')));
        $this->assertFalse($this->evaluator->isDue($cron, $this->at('2024-06-15 04:00:00')));
    }

    public function test_weekly_sunday_accepts_zero_and_seven(): void
    {
        // 2024-01-07 is a Sunday.
        $this->assertTrue($this->evaluator->isDue('0 4 * * 0', $this->at('2024-01-07 04:00:00')));
        $this->assertTrue($this->evaluator->isDue('0 4 * * 7', $this->at('2024-01-07 04:00:00')));
        $this->assertFalse($this->evaluator->isDue('0 4 * * 0', $this->at('2024-01-08 04:00:00')));
    }

    public function test_ranges_lists_and_steps(): void
    {
        $this->assertTrue($this->evaluator->isDue('15,45 * * * *', $this->at('2024-01-01 10:45:00')));
        $this->assertTrue($this->evaluator->isDue('0 9-17 * * 1-5', $this->at('2024-01-01 13:00:00'))); // Monday 13:00
        $this->assertFalse($this->evaluator->isDue('0 9-17 * * 1-5', $this->at('2024-01-06 13:00:00'))); // Saturday
    }

    public function test_next_due_after_is_strictly_after(): void
    {
        $next = $this->evaluator->nextDueAfter('0 */6 * * *', $this->at('2024-01-01 06:00:00'));
        $this->assertSame('2024-01-01 12:00:00', $next->format('Y-m-d H:i:s'));
    }

    public function test_next_due_crosses_day_boundary(): void
    {
        $next = $this->evaluator->nextDueAfter('0 3 * * *', $this->at('2024-01-01 05:00:00'));
        $this->assertSame('2024-01-02 03:00:00', $next->format('Y-m-d H:i:s'));
    }

    public function test_invalid_field_count_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->evaluator->isDue('0 3 * *', $this->at('2024-01-01 03:00:00'));
    }

    private function at(string $s): DateTimeImmutable
    {
        return new DateTimeImmutable($s, new DateTimeZone('UTC'));
    }
}
