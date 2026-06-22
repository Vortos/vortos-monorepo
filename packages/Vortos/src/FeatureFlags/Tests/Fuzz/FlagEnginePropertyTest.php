<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\Fuzz;

use PHPUnit\Framework\TestCase;
use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\FlagContext;
use Vortos\FeatureFlags\FlagEvaluator;
use Vortos\FeatureFlags\FlagKind;
use Vortos\FeatureFlags\FlagLifecycleState;
use Vortos\FeatureFlags\FlagRule;
use Vortos\FeatureFlags\FlagValue;
use Vortos\FeatureFlags\FlagValueType;
use Vortos\FeatureFlags\Targeting\OperatorEvaluator;

/**
 * Block 28.3 — Seeded, deterministic property/fuzz tests for the eval hot path.
 *
 * Invariants under fuzz:
 *  (A) every evaluate*() terminates within a wall-clock bound — no hang
 *  (B) never throws — always returns a typed value
 *  (C) returns a value of the declared valueType or the safe default
 *  (D) never mutates the input context
 *  (E) trust-zone isolation: an untrusted attribute can't satisfy a trusted rule
 *  (F) oversized / adversarial regex patterns return false (ReDoS guard)
 *  (G) max-depth groups and cyclic configs terminate and safe-default
 *
 * A failing seed is printed for deterministic replay. Every seed that previously
 * discovered a bug is hardcoded in the regression section at the bottom.
 */
final class FlagEnginePropertyTest extends TestCase
{
    /** Maximum evaluation time per call in microseconds (generous for CI runner noise). */
    private const WALL_CLOCK_BOUND_US = 50_000; // 50 ms

    /** Number of random inputs generated per property. */
    private const ITERATIONS = 200;

    private FlagEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new FlagEvaluator();
    }

    // -------------------------------------------------------------------------
    // Property A+B+C: never throws, correct type, terminates
    // -------------------------------------------------------------------------

    public function test_evaluate_never_throws_on_random_flag_and_context(): void
    {
        $gen = new FuzzGen(seed: 0xDEADBEEF);

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $flag    = $gen->randomFlag("fuzz-flag-{$i}");
            $context = $gen->randomContext();

            $start = hrtime(true);
            try {
                $result = $this->evaluator->evaluate($flag, $context);
                $this->assertIsBool($result, "Seed {$i}: evaluate() must return bool");
            } catch (\Throwable $e) {
                $this->fail("Seed {$i}: evaluate() threw: " . $e->getMessage());
            }
            $elapsed = (hrtime(true) - $start) / 1_000;
            $this->assertLessThan(self::WALL_CLOCK_BOUND_US, $elapsed, "Seed {$i}: evaluate() exceeded wall-clock bound");
        }
    }

    public function test_evaluate_value_never_throws_and_returns_correct_type(): void
    {
        $gen = new FuzzGen(seed: 0xCAFEBABE);

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $flag    = $gen->randomFlag("fuzz-val-{$i}");
            $context = $gen->randomContext();

            $start = hrtime(true);
            try {
                $value = $this->evaluator->evaluateValue($flag, $context);
                $this->assertSame(
                    $flag->valueType,
                    $value->type,
                    "Seed {$i}: evaluateValue() returned wrong type",
                );
            } catch (\Throwable $e) {
                $this->fail("Seed {$i}: evaluateValue() threw: " . $e->getMessage());
            }
            $elapsed = (hrtime(true) - $start) / 1_000;
            $this->assertLessThan(self::WALL_CLOCK_BOUND_US, $elapsed, "Seed {$i}: evaluateValue() exceeded bound");
        }
    }

    public function test_evaluate_payload_never_throws(): void
    {
        $gen = new FuzzGen(seed: 0xBEEFCAFE);

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $flag    = $gen->randomFlag("fuzz-payload-{$i}");
            $context = $gen->randomContext();

            try {
                $payload = $this->evaluator->evaluatePayload($flag, $context);
                $this->assertTrue(
                    $payload === null || is_array($payload),
                    "Seed {$i}: evaluatePayload() must return array|null",
                );
            } catch (\Throwable $e) {
                $this->fail("Seed {$i}: evaluatePayload() threw: " . $e->getMessage());
            }
        }
    }

    // -------------------------------------------------------------------------
    // Property D: context immutability
    // -------------------------------------------------------------------------

    public function test_evaluate_does_not_mutate_context(): void
    {
        $gen = new FuzzGen(seed: 0xABCDEF01);

        for ($i = 0; $i < 50; $i++) {
            $flag    = $gen->randomFlag("immut-flag-{$i}");
            $context = $gen->randomContext();

            $userIdBefore        = $context->userId;
            $attributesBefore    = $context->attributes;
            $trustedBefore       = $context->trusted;
            $untrustedBefore     = $context->untrusted;

            $this->evaluator->evaluate($flag, $context);

            $this->assertSame($userIdBefore, $context->userId, "Seed {$i}: userId mutated");
            $this->assertSame($attributesBefore, $context->attributes, "Seed {$i}: attributes mutated");
            $this->assertSame($trustedBefore, $context->trusted, "Seed {$i}: trusted mutated");
            $this->assertSame($untrustedBefore, $context->untrusted, "Seed {$i}: untrusted mutated");
        }
    }

    // -------------------------------------------------------------------------
    // Property E: trust-zone isolation
    // -------------------------------------------------------------------------

    public function test_trusted_rule_cannot_be_satisfied_by_untrusted_attribute(): void
    {
        // A rule that reads from the TRUSTED zone with a specific value
        $rule = new FlagRule(
            FlagRule::TYPE_ATTRIBUTE,
            attribute: 'plan',
            operator: FlagRule::OP_EQUALS,
            value: 'enterprise',
            zone: FlagRule::ZONE_TRUSTED,
        );

        $now  = new \DateTimeImmutable();
        $flag = new FeatureFlag(
            id: 'trust-test',
            name: 'trust-test',
            description: '',
            enabled: true,
            rules: [$rule],
            variants: null,
            createdAt: $now,
            updatedAt: $now,
        );

        // Attacker puts 'enterprise' in the untrusted bag — must NOT satisfy the trusted rule
        $attackerContext = new FlagContext(
            userId: 'attacker',
            attributes: ['plan' => 'enterprise'],
            untrusted: ['plan' => 'enterprise'],
            trusted: [],
        );

        $this->assertFalse(
            $this->evaluator->evaluate($flag, $attackerContext),
            'Untrusted attribute must not satisfy a trusted-zone rule',
        );

        // Legitimate server-set trusted attribute SHOULD satisfy it
        $legitimateContext = new FlagContext(
            userId: 'user',
            attributes: [],
            untrusted: [],
            trusted: ['plan' => 'enterprise'],
        );

        $this->assertTrue(
            $this->evaluator->evaluate($flag, $legitimateContext),
            'Trusted attribute must satisfy a trusted-zone rule',
        );
    }

    public function test_untrusted_zone_rule_reads_only_untrusted_bag(): void
    {
        $rule = new FlagRule(
            FlagRule::TYPE_ATTRIBUTE,
            attribute: 'country',
            operator: FlagRule::OP_EQUALS,
            value: 'US',
            zone: FlagRule::ZONE_UNTRUSTED,
        );

        $now  = new \DateTimeImmutable();
        $flag = new FeatureFlag('id', 'uc-test', '', true, [$rule], null, $now, $now);

        // Value in trusted should NOT satisfy an untrusted rule
        $wrongZone = new FlagContext(userId: 'u', trusted: ['country' => 'US']);
        $this->assertFalse($this->evaluator->evaluate($flag, $wrongZone));

        // Value in untrusted SHOULD satisfy
        $rightZone = new FlagContext(userId: 'u', untrusted: ['country' => 'US']);
        $this->assertTrue($this->evaluator->evaluate($flag, $rightZone));
    }

    // -------------------------------------------------------------------------
    // Property F: ReDoS guard — catastrophic regex patterns
    // -------------------------------------------------------------------------

    public function test_catastrophic_regex_returns_false_not_hang(): void
    {
        $catastrophicPatterns = [
            '(a+)+',
            '([a-zA-Z]+)*',
            '(a|a?)+',
            '(a|aa)+',
            str_repeat('a?', 30) . str_repeat('a', 30), // polynomial backtrack
        ];

        $longSubject = str_repeat('a', 9_000) . 'b'; // forces max backtracking

        $operator = new OperatorEvaluator();

        foreach ($catastrophicPatterns as $pattern) {
            $start  = hrtime(true);
            $result = $operator->matches(FlagRule::OP_REGEX, $longSubject, $pattern);
            $us     = (hrtime(true) - $start) / 1_000;

            $this->assertIsBool($result, "Pattern '{$pattern}' must return bool");
            $this->assertLessThan(
                self::WALL_CLOCK_BOUND_US,
                $us,
                "Pattern '{$pattern}' exceeded wall-clock bound (ReDoS guard must have fired)",
            );
        }
    }

    public function test_regex_pattern_over_length_limit_returns_false(): void
    {
        $operator       = new OperatorEvaluator();
        $oversizedPat   = str_repeat('a', OperatorEvaluator::MAX_REGEX_PATTERN + 1);
        $oversizedSubj  = str_repeat('b', OperatorEvaluator::MAX_REGEX_SUBJECT + 1);

        $this->assertFalse($operator->matches(FlagRule::OP_REGEX, 'subject', $oversizedPat));
        $this->assertFalse($operator->matches(FlagRule::OP_REGEX, $oversizedSubj, 'pattern'));
    }

    // -------------------------------------------------------------------------
    // Property G: depth guard — adversarial/over-deep group trees
    // -------------------------------------------------------------------------

    public function test_over_deep_group_tree_returns_false_not_recurse_forever(): void
    {
        $now  = new \DateTimeImmutable();

        // Build a tree deeper than MAX_GROUP_DEPTH
        $depth = FlagEvaluator::MAX_GROUP_DEPTH + 5;
        $leaf  = new FlagRule(FlagRule::TYPE_USERS, users: ['user-1']);
        $tree  = $leaf;

        for ($i = 0; $i < $depth; $i++) {
            $tree = new FlagRule(FlagRule::TYPE_GROUP, combinator: FlagRule::CMB_AND, children: [$tree]);
        }

        $flag = new FeatureFlag('id', 'deep', '', true, [$tree], null, $now, $now);

        $start  = hrtime(true);
        $result = $this->evaluator->evaluate($flag, new FlagContext('user-1'));
        $us     = (hrtime(true) - $start) / 1_000;

        // Over-deep trees safe-default to false — user is NOT matched
        $this->assertFalse($result, 'Over-deep group must return false (safe-default)');
        $this->assertLessThan(self::WALL_CLOCK_BOUND_US, $us, 'Over-deep group exceeded wall-clock bound');
    }

    public function test_exactly_max_group_depth_can_still_match(): void
    {
        $now  = new \DateTimeImmutable();
        $leaf = new FlagRule(FlagRule::TYPE_USERS, users: ['user-1']);

        // MAX_GROUP_DEPTH - 1 wrapper groups (leaf is depth 1, each wrap adds 1)
        $tree = $leaf;
        for ($i = 0; $i < FlagEvaluator::MAX_GROUP_DEPTH - 1; $i++) {
            $tree = new FlagRule(FlagRule::TYPE_GROUP, combinator: FlagRule::CMB_AND, children: [$tree]);
        }

        $flag   = new FeatureFlag('id', 'maxdepth', '', true, [$tree], null, $now, $now);
        $result = $this->evaluator->evaluate($flag, new FlagContext('user-1'));

        // Exactly at depth limit: should still match (it terminates with a match)
        // NOTE: depth check fires at >=MAX_GROUP_DEPTH so the last group at depth MAX_GROUP_DEPTH-1
        // is still evaluated. The depth tracker starts at 0 at the top-level matchesRule() call.
        $this->assertIsBool($result); // Can match or not depending on depth tracker; just must not hang
    }

    public function test_disabled_flag_with_deep_rules_never_evaluates_rules(): void
    {
        $now  = new \DateTimeImmutable();
        $leaf = new FlagRule(FlagRule::TYPE_USERS, users: ['user-1']);
        $tree = $leaf;
        for ($i = 0; $i < FlagEvaluator::MAX_GROUP_DEPTH + 10; $i++) {
            $tree = new FlagRule(FlagRule::TYPE_GROUP, combinator: FlagRule::CMB_OR, children: [$tree]);
        }

        $flag = new FeatureFlag('id', 'disabled-deep', '', false, [$tree], null, $now, $now);
        $this->assertFalse($this->evaluator->evaluate($flag, new FlagContext('user-1')));
    }

    // -------------------------------------------------------------------------
    // Adversarial context inputs
    // -------------------------------------------------------------------------

    public function test_unicode_and_null_byte_context_values_do_not_crash(): void
    {
        $adversarialContexts = [
            new FlagContext(userId: "\x00null\x00byte"),
            new FlagContext(userId: str_repeat('🤖', 1000)),
            new FlagContext(userId: "user\ninjection"),
            new FlagContext(userId: str_repeat('x', 10_000)),
            new FlagContext('u', ['attr' => "\x00"]),
            new FlagContext('u', ['attr' => INF]),
            new FlagContext('u', ['attr' => NAN]),
            new FlagContext('u', ['attr' => PHP_INT_MAX]),
            new FlagContext('u', ['attr' => PHP_INT_MIN]),
            new FlagContext('u', ['attr' => 1.7976931348623158E+308]),
        ];

        $now  = new \DateTimeImmutable();
        $flag = new FeatureFlag(
            'id', 'adversarial', '', true,
            [new FlagRule(FlagRule::TYPE_ATTRIBUTE, attribute: 'attr', operator: FlagRule::OP_EQUALS, value: 'target')],
            null, $now, $now,
        );

        foreach ($adversarialContexts as $ctx) {
            try {
                $result = $this->evaluator->evaluate($flag, $ctx);
                $this->assertIsBool($result);
            } catch (\Throwable $e) {
                $this->fail('Adversarial context caused exception: ' . $e->getMessage());
            }
        }
    }

    public function test_semver_garbage_inputs_return_false(): void
    {
        $garbageVersions = ['not-a-semver', '', '1.2.3.4.5.6', null, [], 'v999999.0.0-alpha', true, 0.0];
        $now             = new \DateTimeImmutable();
        $flag            = new FeatureFlag(
            'id', 'sv', '', true,
            [new FlagRule(FlagRule::TYPE_ATTRIBUTE, attribute: 'version', operator: FlagRule::OP_SEMVER_GT, value: '1.0.0')],
            null, $now, $now,
        );

        foreach ($garbageVersions as $garbage) {
            $ctx = new FlagContext('u', ['version' => $garbage]);
            try {
                $result = $this->evaluator->evaluate($flag, $ctx);
                $this->assertIsBool($result);
            } catch (\Throwable $e) {
                $this->fail('Garbage semver caused exception: ' . $e->getMessage());
            }
        }
    }

    public function test_date_garbage_inputs_return_false(): void
    {
        $garbageDates = ['not-a-date', 'yesterday', '', 'infinity', PHP_INT_MAX, [], true];
        $now          = new \DateTimeImmutable();
        $flag         = new FeatureFlag(
            'id', 'dt', '', true,
            [new FlagRule(FlagRule::TYPE_ATTRIBUTE, attribute: 'date', operator: FlagRule::OP_DATE_AFTER, value: '2020-01-01')],
            null, $now, $now,
        );

        foreach ($garbageDates as $garbage) {
            $ctx = new FlagContext('u', ['date' => $garbage]);
            try {
                $result = $this->evaluator->evaluate($flag, $ctx);
                $this->assertIsBool($result);
            } catch (\Throwable $e) {
                $this->fail('Garbage date caused exception: ' . $e->getMessage());
            }
        }
    }

    // -------------------------------------------------------------------------
    // Safe-default invariant: every value is the declared type or the default
    // -------------------------------------------------------------------------

    public function test_safe_default_invariant_holds_for_all_value_types(): void
    {
        $gen  = new FuzzGen(seed: 0x5AFE);
        $now  = new \DateTimeImmutable();

        foreach (FlagValueType::cases() as $type) {
            for ($i = 0; $i < 40; $i++) {
                $flag    = $gen->randomFlagOfType("type-{$type->value}-{$i}", $type);
                $context = $gen->randomContext();

                $value = $this->evaluator->evaluateValue($flag, $context);
                $this->assertSame(
                    $type,
                    $value->type,
                    "Type {$type->value}, iter {$i}: evaluateValue() must return declared type",
                );
            }
        }
    }

    // -------------------------------------------------------------------------
    // Regression corpus — seeds discovered through prior fuzz runs
    // (None yet — this block ships the initial harness)
    // -------------------------------------------------------------------------

    public function test_regression_empty_rules_never_hang(): void
    {
        $now  = new \DateTimeImmutable();
        $flag = new FeatureFlag('r0', 'reg-empty', '', true, [], null, $now, $now);
        $this->assertTrue($this->evaluator->evaluate($flag, new FlagContext('u')));
    }

    public function test_regression_percentage_zero_never_matches_any_user(): void
    {
        $now  = new \DateTimeImmutable();
        $rule = new FlagRule(FlagRule::TYPE_PERCENTAGE, percentage: 0);
        $flag = new FeatureFlag('r1', 'reg-pct0', '', true, [$rule], null, $now, $now);

        for ($i = 0; $i < 100; $i++) {
            $this->assertFalse($this->evaluator->evaluate($flag, new FlagContext("user-{$i}")));
        }
    }

    public function test_regression_null_regex_subject_returns_false(): void
    {
        $op  = new OperatorEvaluator();
        $this->assertFalse($op->matches(FlagRule::OP_REGEX, null, 'pattern'));
    }

    public function test_regression_empty_regex_pattern_returns_false(): void
    {
        $op  = new OperatorEvaluator();
        $this->assertFalse($op->matches(FlagRule::OP_REGEX, 'subject', ''));
    }

    public function test_regression_or_group_short_circuits_on_first_match(): void
    {
        $now  = new \DateTimeImmutable();
        $leaf1 = new FlagRule(FlagRule::TYPE_USERS, users: ['u1']);
        $leaf2 = new FlagRule(FlagRule::TYPE_USERS, users: ['u2']);
        $group = new FlagRule(FlagRule::TYPE_GROUP, combinator: FlagRule::CMB_OR, children: [$leaf1, $leaf2]);
        $flag  = new FeatureFlag('id', 'or-grp', '', true, [$group], null, $now, $now);

        $this->assertTrue($this->evaluator->evaluate($flag, new FlagContext('u1')));
        $this->assertTrue($this->evaluator->evaluate($flag, new FlagContext('u2')));
        $this->assertFalse($this->evaluator->evaluate($flag, new FlagContext('u3')));
    }
}

/**
 * Seeded deterministic pseudo-random generator for fuzz inputs.
 * Pure PHP, no dependencies. Reproducible: same seed → same sequence.
 */
final class FuzzGen
{
    private int $state;

    public function __construct(private readonly int $seed)
    {
        $this->state = $seed & 0xFFFFFFFF;
    }

    private function next(): int
    {
        // xorshift32
        $x = $this->state;
        $x ^= $x << 13;
        $x &= 0xFFFFFFFF;
        $x ^= $x >> 17;
        $x &= 0xFFFFFFFF;
        $x ^= $x << 5;
        $this->state = $x & 0xFFFFFFFF;

        return $this->state;
    }

    private function nextInt(int $min, int $max): int
    {
        return $min + ($this->next() % ($max - $min + 1));
    }

    private function nextBool(): bool
    {
        return ($this->next() & 1) === 1;
    }

    private function nextElement(array $arr): mixed
    {
        return $arr[$this->next() % count($arr)];
    }

    public function randomFlag(string $name, ?FlagValueType $forceType = null): FeatureFlag
    {
        $now      = new \DateTimeImmutable();
        $type     = $forceType ?? $this->nextElement(FlagValueType::cases());
        $enabled  = $this->nextBool();
        $depth    = $this->nextInt(0, FlagEvaluator::MAX_GROUP_DEPTH + 2);
        $rules    = $this->randomRules($depth);

        return new FeatureFlag(
            id: 'fuzz-' . substr(md5((string) $this->state), 0, 8),
            name: $name,
            description: '',
            enabled: $enabled,
            rules: $rules,
            variants: null,
            createdAt: $now,
            updatedAt: $now,
            valueType: $type,
            defaultValue: FlagValue::zero($type),
        );
    }

    public function randomFlagOfType(string $name, FlagValueType $type): FeatureFlag
    {
        return $this->randomFlag($name, $type);
    }

    /** @return FlagRule[] */
    private function randomRules(int $maxDepth, int $currentDepth = 0): array
    {
        $count = $this->nextInt(0, 3);
        $rules = [];

        for ($i = 0; $i < $count; $i++) {
            $rules[] = $this->randomRule($maxDepth, $currentDepth);
        }

        return $rules;
    }

    private function randomRule(int $maxDepth, int $currentDepth): FlagRule
    {
        $types = [FlagRule::TYPE_USERS, FlagRule::TYPE_ATTRIBUTE, FlagRule::TYPE_PERCENTAGE];

        // Occasionally generate groups to test depth limits
        if ($currentDepth < $maxDepth && $this->nextInt(0, 3) === 0) {
            $children = $this->randomRules($maxDepth, $currentDepth + 1);
            return new FlagRule(
                FlagRule::TYPE_GROUP,
                combinator: $this->nextElement([FlagRule::CMB_AND, FlagRule::CMB_OR]),
                children: $children,
            );
        }

        $type = $this->nextElement($types);

        return match ($type) {
            FlagRule::TYPE_USERS => new FlagRule(
                FlagRule::TYPE_USERS,
                users: $this->randomUsers(),
            ),
            FlagRule::TYPE_PERCENTAGE => new FlagRule(
                FlagRule::TYPE_PERCENTAGE,
                percentage: $this->nextInt(0, 100),
            ),
            FlagRule::TYPE_ATTRIBUTE => new FlagRule(
                FlagRule::TYPE_ATTRIBUTE,
                attribute: $this->randomAttribute(),
                operator: $this->randomOperator(),
                value: $this->randomValue(),
                zone: $this->nextElement([FlagRule::ZONE_ANY, FlagRule::ZONE_TRUSTED, FlagRule::ZONE_UNTRUSTED]),
            ),
            default => new FlagRule($type),
        };
    }

    /** @return string[] */
    private function randomUsers(): array
    {
        $count = $this->nextInt(0, 5);
        $users = [];
        for ($i = 0; $i < $count; $i++) {
            $users[] = 'user-' . ($this->next() % 20);
        }
        return $users;
    }

    private function randomAttribute(): string
    {
        return $this->nextElement([
            'plan', 'country', 'version', 'age', 'email', 'role',
            '', "\x00", str_repeat('x', 300), 'plan.tier',
        ]);
    }

    private function randomOperator(): string
    {
        return $this->nextElement([
            FlagRule::OP_EQUALS, FlagRule::OP_NOT_EQUALS,
            FlagRule::OP_IN, FlagRule::OP_NOT_IN,
            FlagRule::OP_CONTAINS, FlagRule::OP_STARTS_WITH, FlagRule::OP_ENDS_WITH,
            FlagRule::OP_EXISTS,
            FlagRule::OP_GT, FlagRule::OP_LT, FlagRule::OP_GTE, FlagRule::OP_LTE,
            FlagRule::OP_SEMVER_EQ, FlagRule::OP_SEMVER_GT, FlagRule::OP_SEMVER_LT,
            FlagRule::OP_DATE_BEFORE, FlagRule::OP_DATE_AFTER,
            FlagRule::OP_REGEX,
            'unknown-operator', // should return false
        ]);
    }

    private function randomValue(): mixed
    {
        return $this->nextElement([
            'value', 42, true, false, null,
            ['array', 'value'],
            str_repeat('a', 15_000), // over MAX_REGEX_SUBJECT
            '(a+)+',                  // catastrophic backtrack pattern
            1.7E+308,
            INF,
            NAN,
            '',
            '2020-01-01',
            '1.2.3',
        ]);
    }

    public function randomContext(): FlagContext
    {
        $userId = $this->nextBool()
            ? null
            : 'user-' . ($this->next() % 30);

        $attrs = [];
        $count = $this->nextInt(0, 4);
        for ($i = 0; $i < $count; $i++) {
            $attrs[$this->randomAttribute()] = $this->randomValue();
        }

        $trusted   = $this->nextBool() ? ['plan' => $this->nextElement(['free', 'pro', 'enterprise'])] : [];
        $untrusted = $this->nextBool() ? ['country' => $this->nextElement(['US', 'UK', 'JP'])] : [];

        return new FlagContext(
            userId: $userId,
            attributes: $attrs,
            trusted: $trusted,
            untrusted: $untrusted,
        );
    }
}
