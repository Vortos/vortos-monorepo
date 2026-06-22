<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests;

use PHPUnit\Framework\TestCase;
use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\FlagKind;
use Vortos\FeatureFlags\FlagRule;
use Vortos\FeatureFlags\FlagScopeContext;
use Vortos\FeatureFlags\FlagValue;
use Vortos\FeatureFlags\FlagValueType;
use Vortos\FeatureFlags\Prerequisite;
use Vortos\FeatureFlags\RolloutSchedule;


/**
 * Regression guard for the withPayload() positional-arg bug (Block 10).
 *
 * The original `with*` methods used positional `new self(...)` calls and silently dropped
 * trailing constructor fields (bucketBy, kind, prerequisites, variantRules, schedule,
 * requiredScope). They now delegate to a named-arg `withClone()` helper so every
 * `with*` returns a clone with ALL fields preserved.
 */
final class FeatureFlagCloneTest extends TestCase
{
    private FeatureFlag $base;

    protected function setUp(): void
    {
        $rule      = new FlagRule(type: FlagRule::TYPE_USERS, users: ['user-a']);
        $prereq    = new Prerequisite('prereq-flag', FlagValue::bool(true));
        $schedule  = new RolloutSchedule(
            enableAt:  new \DateTimeImmutable('2026-07-01T00:00:00Z'),
        );

        $this->base = new FeatureFlag(
            id:           'abc-123',
            name:         'my-flag',
            description:  'a flag',
            enabled:      true,
            rules:        [$rule],
            variants:     ['on' => 50, 'off' => 50],
            createdAt:    new \DateTimeImmutable('2026-01-01T00:00:00Z'),
            updatedAt:    new \DateTimeImmutable('2026-06-01T00:00:00Z'),
            valueType:    FlagValueType::String,
            defaultValue: FlagValue::string('ctrl'),
            payload:      ['key' => 'value'],
            bucketBy:     FeatureFlag::BUCKET_BY_TENANT,
            kind:         FlagKind::Permission,
            prerequisites: [$prereq],
            variantRules: ['on' => [$rule]],
            schedule:     $schedule,
            requiredScope: 'resource.action.scope',
            environment:  'staging',
        );
    }

    /** Every with* call must preserve all fields NOT being mutated. */

    public function test_with_enabled_preserves_all_other_fields(): void
    {
        $clone = $this->base->withEnabled(false);

        $this->assertFalse($clone->enabled);
        $this->assertSame($this->base->id, $clone->id);
        $this->assertSame($this->base->name, $clone->name);
        $this->assertSame($this->base->description, $clone->description);
        $this->assertSame($this->base->rules, $clone->rules);
        $this->assertSame($this->base->variants, $clone->variants);
        $this->assertSame($this->base->valueType, $clone->valueType);
        $this->assertSame($this->base->payload, $clone->payload);
        $this->assertSame($this->base->bucketBy, $clone->bucketBy);
        $this->assertSame($this->base->kind, $clone->kind);
        $this->assertSame($this->base->prerequisites, $clone->prerequisites);
        $this->assertSame($this->base->variantRules, $clone->variantRules);
        $this->assertSame($this->base->schedule, $clone->schedule);
        $this->assertSame($this->base->requiredScope, $clone->requiredScope);
        $this->assertSame($this->base->environment, $clone->environment);
        $this->assertSame($this->base->createdAt, $clone->createdAt);
    }

    public function test_with_rules_preserves_all_other_fields(): void
    {
        $newRule = new FlagRule(type: FlagRule::TYPE_PERCENTAGE, percentage: 10);
        $clone   = $this->base->withRules([$newRule]);

        $this->assertSame([$newRule], $clone->rules);
        $this->assertTrue($clone->enabled);
        $this->assertSame($this->base->bucketBy, $clone->bucketBy);
        $this->assertSame($this->base->kind, $clone->kind);
        $this->assertSame($this->base->prerequisites, $clone->prerequisites);
        $this->assertSame($this->base->variantRules, $clone->variantRules);
        $this->assertSame($this->base->schedule, $clone->schedule);
        $this->assertSame($this->base->requiredScope, $clone->requiredScope);
        $this->assertSame($this->base->environment, $clone->environment);
        $this->assertSame($this->base->payload, $clone->payload);
        $this->assertSame($this->base->variants, $clone->variants);
    }

    public function test_with_payload_preserves_all_other_fields(): void
    {
        $clone = $this->base->withPayload(['new' => 'data']);

        $this->assertSame(['new' => 'data'], $clone->payload);
        $this->assertTrue($clone->enabled);
        $this->assertSame($this->base->bucketBy, $clone->bucketBy, 'bucketBy must not be dropped');
        $this->assertSame($this->base->kind, $clone->kind, 'kind must not be dropped');
        $this->assertSame($this->base->prerequisites, $clone->prerequisites, 'prerequisites must not be dropped');
        $this->assertSame($this->base->variantRules, $clone->variantRules, 'variantRules must not be dropped');
        $this->assertSame($this->base->schedule, $clone->schedule, 'schedule must not be dropped');
        $this->assertSame($this->base->requiredScope, $clone->requiredScope, 'requiredScope must not be dropped');
        $this->assertSame($this->base->environment, $clone->environment);
        $this->assertSame($this->base->variants, $clone->variants);
        $this->assertSame($this->base->rules, $clone->rules);
    }

    public function test_with_payload_null_clears_payload(): void
    {
        $clone = $this->base->withPayload(null);

        $this->assertNull($clone->payload);
        $this->assertSame($this->base->bucketBy, $clone->bucketBy);
        $this->assertSame($this->base->schedule, $clone->schedule);
    }

    public function test_with_variants_preserves_all_other_fields(): void
    {
        $clone = $this->base->withVariants(['a' => 30, 'b' => 70]);

        $this->assertSame(['a' => 30, 'b' => 70], $clone->variants);
        $this->assertSame($this->base->bucketBy, $clone->bucketBy);
        $this->assertSame($this->base->kind, $clone->kind);
        $this->assertSame($this->base->prerequisites, $clone->prerequisites);
        $this->assertSame($this->base->schedule, $clone->schedule);
        $this->assertSame($this->base->requiredScope, $clone->requiredScope);
        $this->assertSame($this->base->payload, $clone->payload);
        $this->assertSame($this->base->rules, $clone->rules);
    }

    public function test_with_schedule_preserves_all_other_fields(): void
    {
        $newSchedule = new RolloutSchedule(
            enableAt: new \DateTimeImmutable('2026-09-01T00:00:00Z'),
        );

        $clone = $this->base->withSchedule($newSchedule);

        $this->assertSame($newSchedule, $clone->schedule);
        $this->assertSame($this->base->bucketBy, $clone->bucketBy);
        $this->assertSame($this->base->kind, $clone->kind);
        $this->assertSame($this->base->prerequisites, $clone->prerequisites);
        $this->assertSame($this->base->variantRules, $clone->variantRules);
        $this->assertSame($this->base->requiredScope, $clone->requiredScope);
        $this->assertSame($this->base->payload, $clone->payload);
        $this->assertSame($this->base->variants, $clone->variants);
        $this->assertSame($this->base->rules, $clone->rules);
    }

    public function test_with_environment_preserves_all_other_fields(): void
    {
        $clone = $this->base->withEnvironment('dev');

        $this->assertSame('dev', $clone->environment);
        $this->assertTrue($clone->enabled);
        $this->assertSame($this->base->bucketBy, $clone->bucketBy);
        $this->assertSame($this->base->kind, $clone->kind);
        $this->assertSame($this->base->prerequisites, $clone->prerequisites);
        $this->assertSame($this->base->variantRules, $clone->variantRules);
        $this->assertSame($this->base->schedule, $clone->schedule);
        $this->assertSame($this->base->requiredScope, $clone->requiredScope);
        $this->assertSame($this->base->payload, $clone->payload);
        $this->assertSame($this->base->variants, $clone->variants);
        $this->assertSame($this->base->rules, $clone->rules);
    }

    public function test_chained_mutations_each_preserve_all_fields(): void
    {
        $clone = $this->base
            ->withEnabled(false)
            ->withPayload(['extra' => 1])
            ->withEnvironment('dev');

        $this->assertFalse($clone->enabled);
        $this->assertSame(['extra' => 1], $clone->payload);
        $this->assertSame('dev', $clone->environment);
        $this->assertSame($this->base->bucketBy, $clone->bucketBy);
        $this->assertSame($this->base->kind, $clone->kind);
        $this->assertSame($this->base->prerequisites, $clone->prerequisites);
        $this->assertSame($this->base->schedule, $clone->schedule);
        $this->assertSame($this->base->requiredScope, $clone->requiredScope);
    }

    public function test_original_is_immutable(): void
    {
        $this->base->withEnabled(false);
        $this->base->withPayload(null);

        $this->assertTrue($this->base->enabled);
        $this->assertSame(['key' => 'value'], $this->base->payload);
    }
}
