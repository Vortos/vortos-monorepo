<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\Targeting;

use PHPUnit\Framework\TestCase;
use Vortos\FeatureFlags\FlagRule;
use Vortos\FeatureFlags\Targeting\OperatorEvaluator;

final class OperatorEvaluatorTest extends TestCase
{
    private OperatorEvaluator $op;

    protected function setUp(): void
    {
        $this->op = new OperatorEvaluator();
    }

    // --- equality / membership / substring ---

    public function test_equals(): void
    {
        $this->assertTrue($this->op->matches(FlagRule::OP_EQUALS, 'a', 'a'));
        $this->assertFalse($this->op->matches(FlagRule::OP_EQUALS, 'a', 'b'));
    }

    public function test_not_equals(): void
    {
        $this->assertTrue($this->op->matches(FlagRule::OP_NOT_EQUALS, 'a', 'b'));
        $this->assertFalse($this->op->matches(FlagRule::OP_NOT_EQUALS, 'a', 'a'));
    }

    public function test_in_and_not_in(): void
    {
        $this->assertTrue($this->op->matches(FlagRule::OP_IN, 'eu', ['eu', 'us']));
        $this->assertFalse($this->op->matches(FlagRule::OP_IN, 'apac', ['eu', 'us']));
        $this->assertTrue($this->op->matches(FlagRule::OP_NOT_IN, 'apac', ['eu', 'us']));
    }

    public function test_contains_starts_ends(): void
    {
        $this->assertTrue($this->op->matches(FlagRule::OP_CONTAINS, 'alice@acme.com', '@acme'));
        $this->assertTrue($this->op->matches(FlagRule::OP_STARTS_WITH, 'alice@acme.com', 'alice'));
        $this->assertTrue($this->op->matches(FlagRule::OP_ENDS_WITH, 'alice@acme.com', '.com'));
        $this->assertFalse($this->op->matches(FlagRule::OP_STARTS_WITH, 'alice', 'bob'));
    }

    public function test_exists(): void
    {
        $this->assertTrue($this->op->matches(FlagRule::OP_EXISTS, 'anything', null));
        $this->assertTrue($this->op->matches(FlagRule::OP_EXISTS, 0, null));
        $this->assertTrue($this->op->matches(FlagRule::OP_EXISTS, false, null));
        $this->assertFalse($this->op->matches(FlagRule::OP_EXISTS, null, null));
    }

    // --- numeric ---

    public function test_numeric_comparisons(): void
    {
        $this->assertTrue($this->op->matches(FlagRule::OP_GT, 10, 5));
        $this->assertTrue($this->op->matches(FlagRule::OP_GTE, 5, 5));
        $this->assertTrue($this->op->matches(FlagRule::OP_LT, 3, 5));
        $this->assertTrue($this->op->matches(FlagRule::OP_LTE, 5, 5));
        $this->assertFalse($this->op->matches(FlagRule::OP_GT, 5, 10));
    }

    public function test_numeric_coerces_numeric_strings(): void
    {
        $this->assertTrue($this->op->matches(FlagRule::OP_GT, '10', '5'));
    }

    public function test_numeric_non_numeric_is_no_match(): void
    {
        $this->assertFalse($this->op->matches(FlagRule::OP_GT, 'abc', 5));
        $this->assertFalse($this->op->matches(FlagRule::OP_GT, 10, 'xyz'));
    }

    // --- semver ---

    public function test_semver_comparisons(): void
    {
        $this->assertTrue($this->op->matches(FlagRule::OP_SEMVER_GT, '2.0.0', '1.9.9'));
        $this->assertTrue($this->op->matches(FlagRule::OP_SEMVER_LT, '1.0.0', '1.0.1'));
        $this->assertTrue($this->op->matches(FlagRule::OP_SEMVER_EQ, 'v1.2.3', '1.2.3'));
        $this->assertFalse($this->op->matches(FlagRule::OP_SEMVER_GT, '1.0.0', '2.0.0'));
    }

    public function test_semver_malformed_is_no_match(): void
    {
        $this->assertFalse($this->op->matches(FlagRule::OP_SEMVER_GT, 'not.a.version!!', '1.0.0'));
        $this->assertFalse($this->op->matches(FlagRule::OP_SEMVER_GT, '1.0.0', 'garbage'));
    }

    // --- dates ---

    public function test_date_comparisons(): void
    {
        $this->assertTrue($this->op->matches(FlagRule::OP_DATE_BEFORE, '2020-01-01', '2021-01-01'));
        $this->assertTrue($this->op->matches(FlagRule::OP_DATE_AFTER, '2022-01-01', '2021-01-01'));
        $this->assertFalse($this->op->matches(FlagRule::OP_DATE_BEFORE, '2022-01-01', '2021-01-01'));
    }

    public function test_date_malformed_is_no_match(): void
    {
        $this->assertFalse($this->op->matches(FlagRule::OP_DATE_BEFORE, 'not-a-date', '2021-01-01'));
    }

    // --- regex + ReDoS guard (security) ---

    public function test_regex_matches(): void
    {
        $this->assertTrue($this->op->matches(FlagRule::OP_REGEX, 'alice@acme.com', '^[a-z]+@acme\.com$'));
        $this->assertFalse($this->op->matches(FlagRule::OP_REGEX, 'bob@other.com', '^[a-z]+@acme\.com$'));
    }

    public function test_regex_with_slashes_in_pattern(): void
    {
        $this->assertTrue($this->op->matches(FlagRule::OP_REGEX, 'a/b/c', 'a/b/c'));
    }

    public function test_invalid_regex_is_no_match_not_error(): void
    {
        $this->assertFalse($this->op->matches(FlagRule::OP_REGEX, 'x', '('));
    }

    public function test_oversized_regex_inputs_rejected(): void
    {
        $longPattern = str_repeat('a', OperatorEvaluator::MAX_REGEX_PATTERN + 1);
        $this->assertFalse($this->op->matches(FlagRule::OP_REGEX, 'aaa', $longPattern));

        $longSubject = str_repeat('a', OperatorEvaluator::MAX_REGEX_SUBJECT + 1);
        $this->assertFalse($this->op->matches(FlagRule::OP_REGEX, $longSubject, 'a+'));
    }

    public function test_catastrophic_regex_returns_quickly_and_safely(): void
    {
        // Classic catastrophic-backtracking pattern + non-matching tail.
        $pattern = '^(a+)+$';
        $subject = str_repeat('a', 40) . '!';

        $start  = microtime(true);
        $result = $this->op->matches(FlagRule::OP_REGEX, $subject, $pattern);
        $elapsed = microtime(true) - $start;

        $this->assertFalse($result);
        $this->assertLessThan(1.0, $elapsed, 'regex must be bounded by PCRE limits, not hang');
    }

    public function test_unknown_operator_is_no_match(): void
    {
        $this->assertFalse($this->op->matches('made_up', 'a', 'a'));
    }

    public function test_null_actual_is_no_match_except_exists(): void
    {
        foreach (FlagRule::ATTRIBUTE_OPERATORS as $operator) {
            if ($operator === FlagRule::OP_EXISTS) {
                continue;
            }
            $this->assertFalse(
                $this->op->matches($operator, null, 'x'),
                "operator {$operator} with null actual must be no-match",
            );
        }
    }
}
