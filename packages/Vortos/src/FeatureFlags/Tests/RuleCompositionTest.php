<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests;

use PHPUnit\Framework\TestCase;
use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\FlagContext;
use Vortos\FeatureFlags\FlagEvaluator;
use Vortos\FeatureFlags\FlagRule;

final class RuleCompositionTest extends TestCase
{
    private FlagEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new FlagEvaluator();
    }

    public function test_and_group_requires_all_children(): void
    {
        $flag = $this->flag([
            FlagRule::group(FlagRule::CMB_AND, [
                new FlagRule(FlagRule::TYPE_ATTRIBUTE, attribute: 'plan', operator: FlagRule::OP_EQUALS, value: 'pro'),
                new FlagRule(FlagRule::TYPE_ATTRIBUTE, attribute: 'country', operator: FlagRule::OP_EQUALS, value: 'US'),
            ]),
        ]);

        $this->assertTrue($this->evaluator->evaluate($flag, new FlagContext('u', ['plan' => 'pro', 'country' => 'US'])));
        $this->assertFalse($this->evaluator->evaluate($flag, new FlagContext('u', ['plan' => 'pro', 'country' => 'DE'])));
        $this->assertFalse($this->evaluator->evaluate($flag, new FlagContext('u', ['plan' => 'free', 'country' => 'US'])));
    }

    public function test_or_group_requires_any_child(): void
    {
        $flag = $this->flag([
            FlagRule::group(FlagRule::CMB_OR, [
                new FlagRule(FlagRule::TYPE_ATTRIBUTE, attribute: 'plan', operator: FlagRule::OP_EQUALS, value: 'pro'),
                new FlagRule(FlagRule::TYPE_ATTRIBUTE, attribute: 'plan', operator: FlagRule::OP_EQUALS, value: 'enterprise'),
            ]),
        ]);

        $this->assertTrue($this->evaluator->evaluate($flag, new FlagContext('u', ['plan' => 'enterprise'])));
        $this->assertFalse($this->evaluator->evaluate($flag, new FlagContext('u', ['plan' => 'free'])));
    }

    public function test_nested_groups(): void
    {
        // plan=pro AND (country=US OR country=CA)
        $flag = $this->flag([
            FlagRule::group(FlagRule::CMB_AND, [
                new FlagRule(FlagRule::TYPE_ATTRIBUTE, attribute: 'plan', operator: FlagRule::OP_EQUALS, value: 'pro'),
                FlagRule::group(FlagRule::CMB_OR, [
                    new FlagRule(FlagRule::TYPE_ATTRIBUTE, attribute: 'country', operator: FlagRule::OP_EQUALS, value: 'US'),
                    new FlagRule(FlagRule::TYPE_ATTRIBUTE, attribute: 'country', operator: FlagRule::OP_EQUALS, value: 'CA'),
                ]),
            ]),
        ]);

        $this->assertTrue($this->evaluator->evaluate($flag, new FlagContext('u', ['plan' => 'pro', 'country' => 'CA'])));
        $this->assertFalse($this->evaluator->evaluate($flag, new FlagContext('u', ['plan' => 'pro', 'country' => 'DE'])));
        $this->assertFalse($this->evaluator->evaluate($flag, new FlagContext('u', ['plan' => 'free', 'country' => 'US'])));
    }

    public function test_top_level_rules_are_first_match_wins_or(): void
    {
        $flag = $this->flag([
            new FlagRule(FlagRule::TYPE_USERS, users: ['vip']),
            FlagRule::group(FlagRule::CMB_AND, [
                new FlagRule(FlagRule::TYPE_ATTRIBUTE, attribute: 'plan', operator: FlagRule::OP_EQUALS, value: 'pro'),
            ]),
        ]);

        $this->assertTrue($this->evaluator->evaluate($flag, new FlagContext('vip')));
        $this->assertTrue($this->evaluator->evaluate($flag, new FlagContext('other', ['plan' => 'pro'])));
        $this->assertFalse($this->evaluator->evaluate($flag, new FlagContext('other', ['plan' => 'free'])));
    }

    public function test_empty_group_is_no_match(): void
    {
        $flag = $this->flag([FlagRule::group(FlagRule::CMB_AND, [])]);
        $this->assertFalse($this->evaluator->evaluate($flag, new FlagContext('u')));
    }

    public function test_depth_guard_safe_defaults_beyond_max(): void
    {
        // Build a chain deeper than MAX_GROUP_DEPTH; innermost would match, but the
        // guard must cut it off to a safe no-match rather than recursing unbounded.
        $inner = new FlagRule(FlagRule::TYPE_USERS, users: ['u']);
        for ($i = 0; $i < FlagEvaluator::MAX_GROUP_DEPTH + 3; $i++) {
            $inner = FlagRule::group(FlagRule::CMB_AND, [$inner]);
        }

        $flag = $this->flag([$inner]);
        $this->assertFalse($this->evaluator->evaluate($flag, new FlagContext('u')));
    }

    public function test_legacy_flat_rules_still_evaluate_as_or(): void
    {
        // Pre-Block-2 JSON: a flat list of leaf rules. Any match → on.
        $json = [
            ['type' => 'users', 'users' => ['user-1']],
            ['type' => 'attribute', 'attribute' => 'plan', 'operator' => 'equals', 'value' => 'pro'],
        ];
        $rules = array_map(fn(array $r) => FlagRule::fromArray($r), $json);
        $flag  = $this->flag($rules);

        $this->assertTrue($this->evaluator->evaluate($flag, new FlagContext('user-1')));
        $this->assertTrue($this->evaluator->evaluate($flag, new FlagContext('other', ['plan' => 'pro'])));
        $this->assertFalse($this->evaluator->evaluate($flag, new FlagContext('other', ['plan' => 'free'])));
    }

    public function test_group_round_trips_through_serialization(): void
    {
        $rule = FlagRule::group(FlagRule::CMB_AND, [
            new FlagRule(FlagRule::TYPE_ATTRIBUTE, attribute: 'plan', operator: FlagRule::OP_EQUALS, value: 'pro'),
            FlagRule::group(FlagRule::CMB_OR, [
                new FlagRule(FlagRule::TYPE_PERCENTAGE, percentage: 50),
            ]),
        ]);

        $restored = FlagRule::fromArray($rule->toArray());

        $this->assertSame(FlagRule::TYPE_GROUP, $restored->type);
        $this->assertSame(FlagRule::CMB_AND, $restored->combinator);
        $this->assertCount(2, $restored->children);
        $this->assertSame(FlagRule::TYPE_GROUP, $restored->children[1]->type);
        $this->assertSame(50, $restored->children[1]->children[0]->percentage);
    }

    // --- trust zones ---

    public function test_trusted_zone_rule_reads_only_trusted(): void
    {
        $rule = new FlagRule(
            FlagRule::TYPE_ATTRIBUTE, attribute: 'plan', operator: FlagRule::OP_EQUALS,
            value: 'enterprise', zone: FlagRule::ZONE_TRUSTED,
        );
        $flag = $this->flag([$rule]);

        // Spoofed in the untrusted/legacy bag → must NOT match (no privilege escalation).
        $spoofed = new FlagContext('u', attributes: ['plan' => 'enterprise']);
        $this->assertFalse($this->evaluator->evaluate($flag, $spoofed));

        // Server-derived trusted value → matches.
        $legit = new FlagContext('u', trusted: ['plan' => 'enterprise']);
        $this->assertTrue($this->evaluator->evaluate($flag, $legit));
    }

    /** @param FlagRule[] $rules */
    private function flag(array $rules): FeatureFlag
    {
        $now = new \DateTimeImmutable();
        return new FeatureFlag('id', 'comp-flag', '', true, $rules, null, $now, $now);
    }
}
