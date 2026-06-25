<?php

declare(strict_types=1);

namespace Vortos\Iac\Tests\Lifecycle;

use PHPUnit\Framework\TestCase;
use Vortos\Iac\Lifecycle\IacChangeAction;
use Vortos\Iac\Lifecycle\IacPlan;
use Vortos\Iac\Lifecycle\IacPlanSummary;
use Vortos\Iac\Lifecycle\IacResourceChange;

final class IacPlanTest extends TestCase
{
    public function test_has_changes_delegates_to_summary(): void
    {
        $plan = $this->makePlan(new IacPlanSummary(1, 0, 0, 0));
        $this->assertTrue($plan->hasChanges());

        $plan = $this->makePlan(new IacPlanSummary(0, 0, 0, 0));
        $this->assertFalse($plan->hasChanges());
    }

    public function test_is_destructive_delegates_to_summary(): void
    {
        $plan = $this->makePlan(new IacPlanSummary(0, 0, 1, 0));
        $this->assertTrue($plan->isDestructive());

        $plan = $this->makePlan(new IacPlanSummary(1, 0, 0, 0));
        $this->assertFalse($plan->isDestructive());
    }

    public function test_destructive_count_delegates_to_summary(): void
    {
        $plan = $this->makePlan(new IacPlanSummary(0, 0, 2, 3));
        $this->assertSame(5, $plan->destructiveCount());
    }

    public function test_to_reviewable_diff_no_changes(): void
    {
        $plan = $this->makePlan(new IacPlanSummary(0, 0, 0, 0));
        $this->assertStringContainsString('No changes', $plan->toReviewableDiff());
    }

    public function test_to_reviewable_diff_create_only(): void
    {
        $plan = new IacPlan(
            new IacPlanSummary(2, 0, 0, 0),
            [
                new IacResourceChange('aws_instance.a', 'aws_instance', IacChangeAction::Create, 'aws'),
                new IacResourceChange('aws_instance.b', 'aws_instance', IacChangeAction::Create, 'aws'),
            ],
            '/tmp/plan.bin',
            'abc',
            'file-abc',
        );

        $diff = $plan->toReviewableDiff();
        $this->assertStringContainsString('2 to add', $diff);
        $this->assertStringContainsString('+ aws_instance.a', $diff);
        $this->assertStringContainsString('+ aws_instance.b', $diff);
    }

    public function test_to_reviewable_diff_mixed(): void
    {
        $plan = new IacPlan(
            new IacPlanSummary(1, 1, 1, 1),
            [
                new IacResourceChange('a.create', 'a', IacChangeAction::Create, 'p'),
                new IacResourceChange('a.update', 'a', IacChangeAction::Update, 'p'),
                new IacResourceChange('a.delete', 'a', IacChangeAction::Delete, 'p'),
                new IacResourceChange('a.replace', 'a', IacChangeAction::Replace, 'p'),
            ],
            '/tmp/plan.bin',
            'abc',
            'file-abc',
        );

        $diff = $plan->toReviewableDiff();
        $this->assertStringContainsString('+ a.create', $diff);
        $this->assertStringContainsString('~ a.update', $diff);
        $this->assertStringContainsString('- a.delete', $diff);
        $this->assertStringContainsString('-/+ a.replace', $diff);
    }

    public function test_to_reviewable_diff_delete_only(): void
    {
        $plan = new IacPlan(
            new IacPlanSummary(0, 0, 2, 0),
            [
                new IacResourceChange('a.one', 'a', IacChangeAction::Delete, 'p'),
                new IacResourceChange('a.two', 'a', IacChangeAction::Delete, 'p'),
            ],
            '/tmp/plan.bin',
            'abc',
            'file-abc',
        );

        $diff = $plan->toReviewableDiff();
        $this->assertStringContainsString('2 to destroy', $diff);
        $this->assertStringContainsString('- a.one', $diff);
        $this->assertStringContainsString('- a.two', $diff);
    }

    public function test_to_reviewable_diff_replace_only(): void
    {
        $plan = new IacPlan(
            new IacPlanSummary(0, 0, 0, 1),
            [
                new IacResourceChange('a.replaced', 'a', IacChangeAction::Replace, 'p'),
            ],
            '/tmp/plan.bin',
            'abc',
            'file-abc',
        );

        $diff = $plan->toReviewableDiff();
        $this->assertStringContainsString('1 to replace', $diff);
        $this->assertStringContainsString('-/+ a.replaced', $diff);
    }

    public function test_to_reviewable_diff_skips_noop_and_read(): void
    {
        $plan = new IacPlan(
            new IacPlanSummary(1, 0, 0, 0),
            [
                new IacResourceChange('a.noop', 'a', IacChangeAction::NoOp, 'p'),
                new IacResourceChange('a.read', 'a', IacChangeAction::Read, 'p'),
                new IacResourceChange('a.create', 'a', IacChangeAction::Create, 'p'),
            ],
            '/tmp/plan.bin',
            'abc',
            'file-abc',
        );

        $diff = $plan->toReviewableDiff();
        $this->assertStringNotContainsString('a.noop', $diff);
        $this->assertStringNotContainsString('a.read', $diff);
        $this->assertStringContainsString('+ a.create', $diff);
    }

    private function makePlan(IacPlanSummary $summary): IacPlan
    {
        return new IacPlan($summary, [], '/tmp/plan.bin', 'digest', 'file-digest');
    }
}
