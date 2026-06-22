<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests;

use PHPUnit\Framework\TestCase;
use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\FlagContext;
use Vortos\FeatureFlags\FlagEvaluator;
use Vortos\FeatureFlags\FlagRule;
use Vortos\FeatureFlags\FlagValue;
use Vortos\FeatureFlags\FlagValueType;

final class TypedEvaluationTest extends TestCase
{
    private FlagEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new FlagEvaluator();
    }

    // --- bool ---

    public function test_bool_flag_on_is_true_off_is_default(): void
    {
        $on  = $this->flag(enabled: true, type: FlagValueType::Bool, default: FlagValue::bool(false));
        $off = $this->flag(enabled: false, type: FlagValueType::Bool, default: FlagValue::bool(false));

        $this->assertTrue($this->evaluator->evaluateValue($on, new FlagContext('u1'))->asBool());
        $this->assertFalse($this->evaluator->evaluateValue($off, new FlagContext('u1'))->asBool());
    }

    // --- json (payload) ---

    public function test_json_flag_delivers_payload_when_on(): void
    {
        $flag = $this->flag(
            enabled: true,
            type: FlagValueType::Json,
            default: FlagValue::json(['tier' => 'free']),
            payload: ['tier' => 'pro', 'seats' => 5],
        );

        $value = $this->evaluator->evaluateValue($flag, new FlagContext('u1'));
        $this->assertSame(['tier' => 'pro', 'seats' => 5], $value->asJson());
        $this->assertSame(['tier' => 'pro', 'seats' => 5], $this->evaluator->evaluatePayload($flag, new FlagContext('u1')));
    }

    public function test_json_flag_falls_back_to_default_when_off(): void
    {
        $flag = $this->flag(
            enabled: false,
            type: FlagValueType::Json,
            default: FlagValue::json(['tier' => 'free']),
            payload: ['tier' => 'pro'],
        );

        $this->assertSame(['tier' => 'free'], $this->evaluator->evaluateValue($flag, new FlagContext('u1'))->asJson());
        $this->assertNull($this->evaluator->evaluatePayload($flag, new FlagContext('u1')));
    }

    public function test_payload_not_delivered_when_rules_do_not_match(): void
    {
        $flag = $this->flag(
            enabled: true,
            type: FlagValueType::Json,
            default: FlagValue::json(null),
            payload: ['secret' => 'config'],
            rules: [new FlagRule(FlagRule::TYPE_USERS, users: ['vip'])],
        );

        // Non-matching user must never receive the payload (no leak).
        $this->assertNull($this->evaluator->evaluatePayload($flag, new FlagContext('not-vip')));
        // Matching user does.
        $this->assertSame(['secret' => 'config'], $this->evaluator->evaluatePayload($flag, new FlagContext('vip')));
    }

    // --- string / number ---

    public function test_string_flag_serves_default_value(): void
    {
        $flag = $this->flag(enabled: true, type: FlagValueType::String, default: FlagValue::string('blue'));
        $this->assertSame('blue', $this->evaluator->evaluateValue($flag, new FlagContext('u1'))->asString());
    }

    public function test_number_flag_serves_default_value(): void
    {
        $flag = $this->flag(enabled: true, type: FlagValueType::Number, default: FlagValue::number(7.5));
        $this->assertSame(7.5, $this->evaluator->evaluateValue($flag, new FlagContext('u1'))->asNumber());
    }

    // --- safe default invariant ---

    public function test_no_default_set_synthesises_type_zero(): void
    {
        $flag = $this->flag(enabled: false, type: FlagValueType::Number, default: null);
        $this->assertSame(0.0, $this->evaluator->evaluateValue($flag, new FlagContext('u1'))->asNumber());
    }

    public function test_evaluate_value_never_throws_on_malformed_rule(): void
    {
        // A rule with a bogus type must not break typed evaluation — safe default returned.
        $flag = $this->flag(
            enabled: true,
            type: FlagValueType::String,
            default: FlagValue::string('safe'),
            rules: [new FlagRule('not-a-real-type')],
        );

        $value = $this->evaluator->evaluateValue($flag, new FlagContext('u1'));
        $this->assertSame('safe', $value->asString());
    }

    private function flag(
        bool $enabled,
        FlagValueType $type,
        ?FlagValue $default,
        ?array $payload = null,
        array $rules = [],
        string $name = 'typed-flag',
    ): FeatureFlag {
        $now = new \DateTimeImmutable();

        return new FeatureFlag(
            id: 'id-1',
            name: $name,
            description: '',
            enabled: $enabled,
            rules: $rules,
            variants: null,
            createdAt: $now,
            updatedAt: $now,
            valueType: $type,
            defaultValue: $default,
            payload: $payload,
        );
    }
}
