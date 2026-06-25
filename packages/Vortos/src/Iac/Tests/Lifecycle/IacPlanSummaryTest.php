<?php

declare(strict_types=1);

namespace Vortos\Iac\Tests\Lifecycle;

use PHPUnit\Framework\TestCase;
use Vortos\Iac\Lifecycle\IacPlanSummary;

final class IacPlanSummaryTest extends TestCase
{
    public function test_total_sums_all_counts(): void
    {
        $summary = new IacPlanSummary(2, 3, 1, 4);
        $this->assertSame(10, $summary->total());
    }

    public function test_has_changes_returns_true_when_total_is_positive(): void
    {
        $this->assertTrue((new IacPlanSummary(1, 0, 0, 0))->hasChanges());
        $this->assertTrue((new IacPlanSummary(0, 1, 0, 0))->hasChanges());
        $this->assertTrue((new IacPlanSummary(0, 0, 1, 0))->hasChanges());
        $this->assertTrue((new IacPlanSummary(0, 0, 0, 1))->hasChanges());
    }

    public function test_has_changes_returns_false_when_all_zero(): void
    {
        $this->assertFalse((new IacPlanSummary(0, 0, 0, 0))->hasChanges());
    }

    public function test_destructive_count_sums_destroy_and_replace(): void
    {
        $summary = new IacPlanSummary(5, 3, 2, 4);
        $this->assertSame(6, $summary->destructiveCount());
    }

    public function test_is_destructive_when_destroy_or_replace_present(): void
    {
        $this->assertTrue((new IacPlanSummary(0, 0, 1, 0))->isDestructive());
        $this->assertTrue((new IacPlanSummary(0, 0, 0, 1))->isDestructive());
        $this->assertTrue((new IacPlanSummary(0, 0, 2, 3))->isDestructive());
    }

    public function test_is_not_destructive_when_no_destroy_or_replace(): void
    {
        $this->assertFalse((new IacPlanSummary(5, 3, 0, 0))->isDestructive());
        $this->assertFalse((new IacPlanSummary(0, 0, 0, 0))->isDestructive());
    }

    public function test_to_array_returns_all_counts(): void
    {
        $summary = new IacPlanSummary(1, 2, 3, 4);
        $this->assertSame([
            'add' => 1,
            'change' => 2,
            'destroy' => 3,
            'replace' => 4,
        ], $summary->toArray());
    }

    public function test_negative_add_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new IacPlanSummary(-1, 0, 0, 0);
    }

    public function test_negative_change_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new IacPlanSummary(0, -1, 0, 0);
    }

    public function test_negative_destroy_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new IacPlanSummary(0, 0, -1, 0);
    }

    public function test_negative_replace_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new IacPlanSummary(0, 0, 0, -1);
    }

    public function test_zero_summary_is_valid(): void
    {
        $summary = new IacPlanSummary(0, 0, 0, 0);
        $this->assertSame(0, $summary->total());
        $this->assertSame(0, $summary->destructiveCount());
    }
}
