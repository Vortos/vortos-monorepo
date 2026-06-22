<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Targeting;

use Vortos\FeatureFlags\FlagRule;

/**
 * Evaluates a single attribute operator against an actual context value.
 *
 * Every operator is **total**: malformed input (non-numeric for a numeric op,
 * unparseable date/semver, bad regex, missing attribute) returns `false`
 * (no-match) — never an exception. This is both correctness (safe default) and
 * security (no eval-time crash, no ReDoS, from crafted context — PLATFORM §6).
 */
final class OperatorEvaluator
{
    /** Reject patterns / subjects longer than this before touching PCRE (ReDoS guard). */
    public const MAX_REGEX_PATTERN = 1_000;
    public const MAX_REGEX_SUBJECT = 10_000;

    public function matches(string $operator, mixed $actual, mixed $expected): bool
    {
        // `exists` is the only operator meaningful when the attribute is absent.
        if ($operator === FlagRule::OP_EXISTS) {
            return $actual !== null;
        }

        if ($actual === null) {
            return false;
        }

        return match ($operator) {
            FlagRule::OP_EQUALS      => $actual === $expected,
            FlagRule::OP_NOT_EQUALS  => $actual !== $expected,
            FlagRule::OP_IN          => in_array($actual, (array) $expected, true),
            FlagRule::OP_NOT_IN      => !in_array($actual, (array) $expected, true),
            FlagRule::OP_CONTAINS    => str_contains((string) $actual, (string) $expected),
            FlagRule::OP_STARTS_WITH => str_starts_with((string) $actual, (string) $expected),
            FlagRule::OP_ENDS_WITH   => str_ends_with((string) $actual, (string) $expected),

            FlagRule::OP_GT  => $this->numeric($actual, $expected, fn(float $a, float $b) => $a > $b),
            FlagRule::OP_LT  => $this->numeric($actual, $expected, fn(float $a, float $b) => $a < $b),
            FlagRule::OP_GTE => $this->numeric($actual, $expected, fn(float $a, float $b) => $a >= $b),
            FlagRule::OP_LTE => $this->numeric($actual, $expected, fn(float $a, float $b) => $a <= $b),

            FlagRule::OP_SEMVER_EQ => $this->semver($actual, $expected, '=='),
            FlagRule::OP_SEMVER_GT => $this->semver($actual, $expected, '>'),
            FlagRule::OP_SEMVER_LT => $this->semver($actual, $expected, '<'),

            FlagRule::OP_DATE_BEFORE => $this->date($actual, $expected, fn(int $a, int $b) => $a < $b),
            FlagRule::OP_DATE_AFTER  => $this->date($actual, $expected, fn(int $a, int $b) => $a > $b),

            FlagRule::OP_REGEX => $this->regex((string) $expected, (string) $actual),

            default => false,
        };
    }

    private function numeric(mixed $actual, mixed $expected, callable $cmp): bool
    {
        $a = $this->toFloat($actual);
        $b = $this->toFloat($expected);

        return $a !== null && $b !== null && $cmp($a, $b);
    }

    private function toFloat(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric(trim($value))) {
            return (float) trim($value);
        }

        return null;
    }

    private function semver(mixed $actual, mixed $expected, string $op): bool
    {
        $a = $this->normalizeSemver($actual);
        $b = $this->normalizeSemver($expected);

        if ($a === null || $b === null) {
            return false;
        }

        return version_compare($a, $b, $op);
    }

    private function normalizeSemver(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return null;
        }

        $value = trim((string) $value);

        // Accept a leading 'v' and basic MAJOR[.MINOR[.PATCH]][-prerelease] forms.
        $value = ltrim($value, 'vV');

        if (!preg_match('/^\d+(\.\d+){0,2}(-[0-9A-Za-z.\-]+)?$/', $value)) {
            return null;
        }

        return $value;
    }

    private function date(mixed $actual, mixed $expected, callable $cmp): bool
    {
        $a = $this->toTimestamp($actual);
        $b = $this->toTimestamp($expected);

        return $a !== null && $b !== null && $cmp($a, $b);
    }

    private function toTimestamp(mixed $value): ?int
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }

        try {
            return (new \DateTimeImmutable(is_int($value) ? "@{$value}" : $value))->getTimestamp();
        } catch (\Throwable) {
            return null;
        }
    }

    private function regex(string $pattern, string $subject): bool
    {
        if ($pattern === ''
            || strlen($pattern) > self::MAX_REGEX_PATTERN
            || strlen($subject) > self::MAX_REGEX_SUBJECT
        ) {
            return false;
        }

        // Authors supply a bare pattern; we own the delimiter and never enable eval.
        // PCRE's backtrack/recursion limits turn catastrophic patterns into a `false`
        // return (treated as no-match) rather than a hang.
        $delimited = '/' . str_replace('/', '\/', $pattern) . '/';

        $result = @preg_match($delimited, $subject);

        return $result === 1;
    }
}
