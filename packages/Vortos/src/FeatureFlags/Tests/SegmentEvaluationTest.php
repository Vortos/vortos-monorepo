<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests;

use PHPUnit\Framework\TestCase;
use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\FlagContext;
use Vortos\FeatureFlags\FlagEvaluator;
use Vortos\FeatureFlags\FlagRule;
use Vortos\FeatureFlags\Segment;
use Vortos\FeatureFlags\SegmentRegistry;
use Vortos\FeatureFlags\Storage\SegmentStorageInterface;

final class SegmentEvaluationTest extends TestCase
{
    public function test_flag_matches_when_context_is_in_segment(): void
    {
        $segment = $this->segment('enterprise-eu', [
            FlagRule::group(FlagRule::CMB_AND, [
                new FlagRule(FlagRule::TYPE_ATTRIBUTE, attribute: 'plan', operator: FlagRule::OP_EQUALS, value: 'enterprise'),
                new FlagRule(FlagRule::TYPE_ATTRIBUTE, attribute: 'region', operator: FlagRule::OP_EQUALS, value: 'eu'),
            ]),
        ]);

        $evaluator = $this->evaluatorWith([$segment]);
        $flag      = $this->flagReferencing('enterprise-eu');

        $in  = new FlagContext('u', ['plan' => 'enterprise', 'region' => 'eu']);
        $out = new FlagContext('u', ['plan' => 'enterprise', 'region' => 'us']);

        $this->assertTrue($evaluator->evaluate($flag, $in));
        $this->assertFalse($evaluator->evaluate($flag, $out));
    }

    public function test_missing_segment_is_safe_no_match(): void
    {
        $evaluator = $this->evaluatorWith([]); // no segments at all
        $flag      = $this->flagReferencing('does-not-exist');

        $this->assertFalse($evaluator->evaluate($flag, new FlagContext('u', ['plan' => 'enterprise'])));
    }

    public function test_segment_change_propagates_to_referencing_flags(): void
    {
        // Two flags reference the same segment; changing the segment changes both.
        $storage = $this->createMock(SegmentStorageInterface::class);

        // First definition: only 'pro' is in the segment.
        $storage->method('findAll')->willReturnOnConsecutiveCalls(
            [$this->segment('audience', [new FlagRule(FlagRule::TYPE_ATTRIBUTE, attribute: 'plan', operator: FlagRule::OP_EQUALS, value: 'pro')])],
            [$this->segment('audience', [new FlagRule(FlagRule::TYPE_ATTRIBUTE, attribute: 'plan', operator: FlagRule::OP_EQUALS, value: 'free')])],
        );

        $registry  = new SegmentRegistry($storage);
        $evaluator = new FlagEvaluator(segments: $registry);
        $flagA     = $this->flagReferencing('audience', 'flag-a');
        $flagB     = $this->flagReferencing('audience', 'flag-b');

        $pro = new FlagContext('u', ['plan' => 'pro']);
        $this->assertTrue($evaluator->evaluate($flagA, $pro));
        $this->assertTrue($evaluator->evaluate($flagB, $pro));

        // Segment redefined; reset clears the per-request memo → reload.
        $registry->reset();
        $this->assertFalse($evaluator->evaluate($flagA, $pro), 'flag-a should follow the new segment definition');
        $this->assertTrue($evaluator->evaluate($flagA, new FlagContext('u', ['plan' => 'free'])));
    }

    public function test_segment_can_combine_with_other_top_level_rules(): void
    {
        $segment   = $this->segment('vips', [new FlagRule(FlagRule::TYPE_USERS, users: ['vip'])]);
        $evaluator = $this->evaluatorWith([$segment]);

        // Top-level OR: in segment OR plan=pro.
        $flag = $this->flag([
            new FlagRule(FlagRule::TYPE_SEGMENT, segment: 'vips'),
            new FlagRule(FlagRule::TYPE_ATTRIBUTE, attribute: 'plan', operator: FlagRule::OP_EQUALS, value: 'pro'),
        ]);

        $this->assertTrue($evaluator->evaluate($flag, new FlagContext('vip')));
        $this->assertTrue($evaluator->evaluate($flag, new FlagContext('other', ['plan' => 'pro'])));
        $this->assertFalse($evaluator->evaluate($flag, new FlagContext('other', ['plan' => 'free'])));
    }

    /** @param Segment[] $segments */
    private function evaluatorWith(array $segments): FlagEvaluator
    {
        $storage = $this->createMock(SegmentStorageInterface::class);
        $storage->method('findAll')->willReturn($segments);

        return new FlagEvaluator(segments: new SegmentRegistry($storage));
    }

    /** @param FlagRule[] $rules */
    private function segment(string $name, array $rules): Segment
    {
        $now = new \DateTimeImmutable('2024-01-01');
        return new Segment('id-' . $name, $name, '', $rules, $now, $now);
    }

    private function flagReferencing(string $segment, string $name = 'gated-flag'): FeatureFlag
    {
        return $this->flag([new FlagRule(FlagRule::TYPE_SEGMENT, segment: $segment)], $name);
    }

    /** @param FlagRule[] $rules */
    private function flag(array $rules, string $name = 'gated-flag'): FeatureFlag
    {
        $now = new \DateTimeImmutable();
        return new FeatureFlag('id', $name, '', true, $rules, null, $now, $now);
    }
}
