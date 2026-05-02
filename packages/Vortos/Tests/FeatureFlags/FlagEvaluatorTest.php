<?php

declare(strict_types=1);

namespace Vortos\Tests\FeatureFlags;

use PHPUnit\Framework\TestCase;
use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\FlagContext;
use Vortos\FeatureFlags\FlagEvaluator;
use Vortos\FeatureFlags\FlagRule;

final class FlagEvaluatorTest extends TestCase
{
    private FlagEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new FlagEvaluator();
    }

    // --- master switch ---

    public function test_disabled_flag_is_always_false(): void
    {
        $flag = $this->flag(enabled: false, rules: []);
        $this->assertFalse($this->evaluator->evaluate($flag, new FlagContext('user-1')));
    }

    public function test_enabled_flag_with_no_rules_is_always_true(): void
    {
        $flag = $this->flag(enabled: true, rules: []);
        $this->assertTrue($this->evaluator->evaluate($flag, new FlagContext('user-1')));
        $this->assertTrue($this->evaluator->evaluate($flag, new FlagContext()));
    }

    // --- users rule ---

    public function test_users_rule_matches_listed_user(): void
    {
        $flag = $this->flag(rules: [new FlagRule(FlagRule::TYPE_USERS, users: ['user-1', 'user-2'])]);
        $this->assertTrue($this->evaluator->evaluate($flag, new FlagContext('user-1')));
    }

    public function test_users_rule_does_not_match_unlisted_user(): void
    {
        $flag = $this->flag(rules: [new FlagRule(FlagRule::TYPE_USERS, users: ['user-1'])]);
        $this->assertFalse($this->evaluator->evaluate($flag, new FlagContext('user-999')));
    }

    public function test_users_rule_does_not_match_anonymous(): void
    {
        $flag = $this->flag(rules: [new FlagRule(FlagRule::TYPE_USERS, users: ['user-1'])]);
        $this->assertFalse($this->evaluator->evaluate($flag, new FlagContext()));
    }

    // --- percentage rule ---

    public function test_percentage_100_matches_all_users(): void
    {
        $flag = $this->flag(rules: [new FlagRule(FlagRule::TYPE_PERCENTAGE, percentage: 100)]);

        for ($i = 0; $i < 20; $i++) {
            $this->assertTrue($this->evaluator->evaluate($flag, new FlagContext("user-{$i}")));
        }
    }

    public function test_percentage_0_matches_no_users(): void
    {
        $flag = $this->flag(rules: [new FlagRule(FlagRule::TYPE_PERCENTAGE, percentage: 0)]);

        for ($i = 0; $i < 20; $i++) {
            $this->assertFalse($this->evaluator->evaluate($flag, new FlagContext("user-{$i}")));
        }
    }

    public function test_percentage_is_deterministic_for_same_user(): void
    {
        $flag    = $this->flag(rules: [new FlagRule(FlagRule::TYPE_PERCENTAGE, percentage: 50)]);
        $context = new FlagContext('user-stable');

        $first = $this->evaluator->evaluate($flag, $context);

        for ($i = 0; $i < 10; $i++) {
            $this->assertSame($first, $this->evaluator->evaluate($flag, $context));
        }
    }

    public function test_percentage_without_user_id_is_false(): void
    {
        $flag = $this->flag(rules: [new FlagRule(FlagRule::TYPE_PERCENTAGE, percentage: 100)]);
        $this->assertFalse($this->evaluator->evaluate($flag, new FlagContext()));
    }

    public function test_percentage_distributes_roughly_correctly(): void
    {
        $flag  = $this->flag(name: 'pct-test', rules: [new FlagRule(FlagRule::TYPE_PERCENTAGE, percentage: 50)]);
        $hits  = 0;
        $total = 1000;

        for ($i = 0; $i < $total; $i++) {
            if ($this->evaluator->evaluate($flag, new FlagContext("user-{$i}"))) {
                $hits++;
            }
        }

        // Should be roughly 50% ± 5%
        $this->assertGreaterThan(400, $hits);
        $this->assertLessThan(600, $hits);
    }

    // --- attribute rule ---

    public function test_attribute_equals_matches(): void
    {
        $flag = $this->flag(rules: [
            new FlagRule(FlagRule::TYPE_ATTRIBUTE, attribute: 'plan', operator: FlagRule::OP_EQUALS, value: 'enterprise'),
        ]);

        $this->assertTrue($this->evaluator->evaluate($flag, new FlagContext('u1', ['plan' => 'enterprise'])));
        $this->assertFalse($this->evaluator->evaluate($flag, new FlagContext('u1', ['plan' => 'free'])));
    }

    public function test_attribute_not_equals_matches(): void
    {
        $flag = $this->flag(rules: [
            new FlagRule(FlagRule::TYPE_ATTRIBUTE, attribute: 'plan', operator: FlagRule::OP_NOT_EQUALS, value: 'free'),
        ]);

        $this->assertTrue($this->evaluator->evaluate($flag, new FlagContext('u1', ['plan' => 'enterprise'])));
        $this->assertFalse($this->evaluator->evaluate($flag, new FlagContext('u1', ['plan' => 'free'])));
    }

    public function test_attribute_in_matches(): void
    {
        $flag = $this->flag(rules: [
            new FlagRule(FlagRule::TYPE_ATTRIBUTE, attribute: 'region', operator: FlagRule::OP_IN, value: ['eu-west', 'eu-central']),
        ]);

        $this->assertTrue($this->evaluator->evaluate($flag, new FlagContext('u1', ['region' => 'eu-west'])));
        $this->assertFalse($this->evaluator->evaluate($flag, new FlagContext('u1', ['region' => 'us-east'])));
    }

    public function test_attribute_not_in_matches(): void
    {
        $flag = $this->flag(rules: [
            new FlagRule(FlagRule::TYPE_ATTRIBUTE, attribute: 'region', operator: FlagRule::OP_NOT_IN, value: ['us-east', 'us-west']),
        ]);

        $this->assertTrue($this->evaluator->evaluate($flag, new FlagContext('u1', ['region' => 'eu-west'])));
        $this->assertFalse($this->evaluator->evaluate($flag, new FlagContext('u1', ['region' => 'us-east'])));
    }

    public function test_attribute_contains_matches(): void
    {
        $flag = $this->flag(rules: [
            new FlagRule(FlagRule::TYPE_ATTRIBUTE, attribute: 'email', operator: FlagRule::OP_CONTAINS, value: '@acme.com'),
        ]);

        $this->assertTrue($this->evaluator->evaluate($flag, new FlagContext('u1', ['email' => 'alice@acme.com'])));
        $this->assertFalse($this->evaluator->evaluate($flag, new FlagContext('u1', ['email' => 'alice@other.com'])));
    }

    public function test_attribute_missing_from_context_is_false(): void
    {
        $flag = $this->flag(rules: [
            new FlagRule(FlagRule::TYPE_ATTRIBUTE, attribute: 'plan', operator: FlagRule::OP_EQUALS, value: 'enterprise'),
        ]);

        $this->assertFalse($this->evaluator->evaluate($flag, new FlagContext('u1', [])));
    }

    // --- rule chain ---

    public function test_first_matching_rule_wins(): void
    {
        $flag = $this->flag(rules: [
            new FlagRule(FlagRule::TYPE_USERS, users: ['vip-user']),         // rule 1
            new FlagRule(FlagRule::TYPE_PERCENTAGE, percentage: 0),          // rule 2 — 0% would block
        ]);

        // vip-user matches rule 1, never reaches rule 2
        $this->assertTrue($this->evaluator->evaluate($flag, new FlagContext('vip-user')));
        // other user skips rule 1, hits rule 2 (0%) → false
        $this->assertFalse($this->evaluator->evaluate($flag, new FlagContext('regular-user')));
    }

    public function test_no_matching_rule_returns_false(): void
    {
        $flag = $this->flag(rules: [
            new FlagRule(FlagRule::TYPE_USERS, users: ['user-1']),
        ]);

        $this->assertFalse($this->evaluator->evaluate($flag, new FlagContext('user-2')));
    }

    // --- variants ---

    public function test_variant_returns_control_when_disabled(): void
    {
        $flag = $this->flag(enabled: false, variants: ['control' => 50, 'blue' => 50]);
        $this->assertSame('control', $this->evaluator->evaluateVariant($flag, new FlagContext('u1')));
    }

    public function test_variant_returns_control_when_no_user_id(): void
    {
        $flag = $this->flag(variants: ['control' => 50, 'blue' => 50]);
        $this->assertSame('control', $this->evaluator->evaluateVariant($flag, new FlagContext()));
    }

    public function test_variant_returns_control_when_no_variants_defined(): void
    {
        $flag = $this->flag(variants: null);
        $this->assertSame('control', $this->evaluator->evaluateVariant($flag, new FlagContext('u1')));
    }

    public function test_variant_is_deterministic_for_same_user(): void
    {
        $flag = $this->flag(variants: ['control' => 34, 'blue' => 33, 'green' => 33]);
        $ctx  = new FlagContext('stable-user');

        $first = $this->evaluator->evaluateVariant($flag, $ctx);
        for ($i = 0; $i < 10; $i++) {
            $this->assertSame($first, $this->evaluator->evaluateVariant($flag, $ctx));
        }
    }

    public function test_variant_result_is_one_of_defined_buckets(): void
    {
        $flag    = $this->flag(variants: ['control' => 34, 'blue' => 33, 'green' => 33]);
        $buckets = ['control', 'blue', 'green'];

        for ($i = 0; $i < 50; $i++) {
            $result = $this->evaluator->evaluateVariant($flag, new FlagContext("user-{$i}"));
            $this->assertContains($result, $buckets);
        }
    }

    // --- helpers ---

    private function flag(
        bool $enabled = true,
        array $rules = [],
        ?array $variants = null,
        string $name = 'test-flag',
    ): FeatureFlag {
        $now = new \DateTimeImmutable();
        return new FeatureFlag('id-1', $name, '', $enabled, $rules, $variants, $now, $now);
    }
}
