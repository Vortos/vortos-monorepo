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
use Vortos\FeatureFlags\Prerequisite;

/**
 * The safe-default invariant: no matter how malformed the flag or context, evaluation
 * never throws into the request — it returns the flag's declared default.
 */
final class SafeDefaultChaosTest extends TestCase
{
    private FlagEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new FlagEvaluator();
    }

    public function test_bogus_rule_types_safe_default(): void
    {
        $flag = $this->flag([
            new FlagRule('not-a-type'),
            new FlagRule(FlagRule::TYPE_ATTRIBUTE, attribute: 'x', operator: 'made-up-op', value: 1),
        ], default: FlagValue::bool(false));

        $this->assertFalse($this->evaluator->evaluate($flag, new FlagContext('u')));
        $this->assertFalse($this->evaluator->evaluateValue($flag, new FlagContext('u'))->asBool());
    }

    public function test_malformed_contexts_never_throw(): void
    {
        $flag = $this->flag([
            new FlagRule(FlagRule::TYPE_ATTRIBUTE, attribute: 'age', operator: FlagRule::OP_GT, value: 18),
        ]);

        $contexts = [
            new FlagContext(),
            new FlagContext('u'),
            new FlagContext('u', ['age' => 'not-a-number']),
            new FlagContext('u', ['age' => null]),
            new FlagContext('u', ['age' => ['nested' => 'array']]),
            new FlagContext(null, [], ['weird' => new \stdClass()]),
        ];

        foreach ($contexts as $ctx) {
            // Must not throw; result is a bool either way.
            $this->assertIsBool($this->evaluator->evaluate($flag, $ctx));
        }
    }

    public function test_missing_prerequisite_yields_default_not_error(): void
    {
        $flag = $this->flag([], prerequisites: [Prerequisite::on('nonexistent')], default: FlagValue::bool(false));
        $this->assertFalse($this->evaluator->evaluate($flag, new FlagContext('u')));
    }

    public function test_json_flag_returns_default_payload_on_chaos(): void
    {
        $flag = new FeatureFlag(
            'id', 'cfg', '', true,
            [new FlagRule('garbage')], null,
            new \DateTimeImmutable(), new \DateTimeImmutable(),
            valueType: FlagValueType::Json,
            defaultValue: FlagValue::json(['safe' => true]),
            payload: ['live' => true],
        );

        // Rule is garbage → flag not on → default payload (not the live payload, not an error).
        $this->assertSame(['safe' => true], $this->evaluator->evaluateValue($flag, new FlagContext('u'))->asJson());
        $this->assertNull($this->evaluator->evaluatePayload($flag, new FlagContext('u')));
    }

    public function test_deeply_nested_groups_safe_default(): void
    {
        $inner = new FlagRule(FlagRule::TYPE_USERS, users: ['u']);
        for ($i = 0; $i < 50; $i++) {
            $inner = FlagRule::group(FlagRule::CMB_AND, [$inner]);
        }

        $flag = $this->flag([$inner]);
        $this->assertFalse($this->evaluator->evaluate($flag, new FlagContext('u')));
    }

    /**
     * @param FlagRule[] $rules
     * @param Prerequisite[] $prerequisites
     */
    private function flag(array $rules, array $prerequisites = [], ?FlagValue $default = null): FeatureFlag
    {
        $now = new \DateTimeImmutable();
        return new FeatureFlag(
            'id', 'chaos', '', true, $rules, null, $now, $now,
            defaultValue: $default,
            prerequisites: $prerequisites,
        );
    }
}
