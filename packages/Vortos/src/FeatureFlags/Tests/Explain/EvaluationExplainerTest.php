<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\Explain;

use PHPUnit\Framework\TestCase;
use Vortos\FeatureFlags\Authz\FlagAuthzGateInterface;
use Vortos\FeatureFlags\Explain\EvaluationExplainer;
use Vortos\FeatureFlags\Explain\EvaluationReason;
use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\FlagContext;
use Vortos\FeatureFlags\FlagKind;
use Vortos\FeatureFlags\FlagLifecycleState;
use Vortos\FeatureFlags\FlagResolverInterface;
use Vortos\FeatureFlags\FlagRule;
use Vortos\FeatureFlags\FlagValue;
use Vortos\FeatureFlags\FlagValueType;
use Vortos\FeatureFlags\Prerequisite;
use Vortos\FeatureFlags\RolloutSchedule;

final class EvaluationExplainerTest extends TestCase
{
    private EvaluationExplainer $explainer;

    protected function setUp(): void
    {
        $this->explainer = new EvaluationExplainer();
    }

    // ── Disabled / Archived ──

    public function test_disabled_flag_returns_disabled_reason(): void
    {
        $flag   = $this->flag(enabled: false);
        $detail = $this->explainer->explain($flag, new FlagContext('u1'));

        $this->assertSame(EvaluationReason::Disabled, $detail->reason);
        $this->assertFalse($detail->value->asBool());
        $this->assertSame('control', $detail->variant);
    }

    public function test_archived_flag_returns_archived_reason(): void
    {
        $flag   = $this->flag(enabled: true, lifecycle: FlagLifecycleState::Archived);
        $detail = $this->explainer->explain($flag, new FlagContext('u1'));

        $this->assertSame(EvaluationReason::Archived, $detail->reason);
        $this->assertFalse($detail->value->asBool());
    }

    // ── Authz denied ──

    public function test_authz_denied_returns_authz_denied_reason(): void
    {
        $authz = $this->createMock(FlagAuthzGateInterface::class);
        $authz->method('allows')->willReturn(false);

        $explainer = new EvaluationExplainer(authz: $authz);
        $flag      = $this->flag(enabled: true);
        $detail    = $explainer->explain($flag, new FlagContext('u1'));

        $this->assertSame(EvaluationReason::AuthzDenied, $detail->reason);
        $this->assertSame('control', $detail->variant);
    }

    // ── Prerequisites ──

    public function test_prerequisite_failed_returns_reason_with_flag_name(): void
    {
        $resolver = $this->createMock(FlagResolverInterface::class);
        $resolver->method('resolve')->willReturn(null);

        $explainer = new EvaluationExplainer(flags: $resolver);
        $flag      = $this->flag(enabled: true, prerequisites: [
            new Prerequisite('prereq-flag', FlagValue::bool(true)),
        ]);

        $detail = $explainer->explain($flag, new FlagContext('u1'));

        $this->assertSame(EvaluationReason::PrerequisiteFailed, $detail->reason);
        $this->assertSame('prereq-flag', $detail->prerequisiteFlag);
    }

    public function test_prerequisite_value_mismatch_returns_failed(): void
    {
        $prereqFlag = $this->flag(name: 'prereq-flag', enabled: false);
        $resolver   = $this->createMock(FlagResolverInterface::class);
        $resolver->method('resolve')->willReturn($prereqFlag);

        $explainer = new EvaluationExplainer(flags: $resolver);
        $flag      = $this->flag(enabled: true, prerequisites: [
            new Prerequisite('prereq-flag', FlagValue::bool(true)),
        ]);

        $detail = $explainer->explain($flag, new FlagContext('u1'));

        $this->assertSame(EvaluationReason::PrerequisiteFailed, $detail->reason);
    }

    // ── Rule matching ──

    public function test_user_target_match_returns_target_match_reason(): void
    {
        $flag = $this->flag(enabled: true, rules: [
            new FlagRule(type: FlagRule::TYPE_USERS, users: ['u1', 'u2']),
        ]);

        $detail = $this->explainer->explain($flag, new FlagContext('u1'));

        $this->assertSame(EvaluationReason::TargetMatch, $detail->reason);
        $this->assertSame(0, $detail->matchedRuleIndex);
        $this->assertTrue($detail->value->asBool());
    }

    public function test_attribute_match_returns_rule_match_reason(): void
    {
        $flag = $this->flag(enabled: true, rules: [
            new FlagRule(type: FlagRule::TYPE_ATTRIBUTE, attribute: 'plan', operator: 'equals', value: 'enterprise'),
        ]);

        $context = new FlagContext('u1', trusted: ['plan' => 'enterprise']);
        $detail  = $this->explainer->explain($flag, $context);

        $this->assertSame(EvaluationReason::RuleMatch, $detail->reason);
        $this->assertSame(0, $detail->matchedRuleIndex);
        $this->assertNotNull($detail->matchedRuleDescription);
    }

    public function test_percentage_match_returns_rollout_reason_with_bucket(): void
    {
        $flag = $this->flag(enabled: true, rules: [
            new FlagRule(type: FlagRule::TYPE_PERCENTAGE, percentage: 100),
        ]);

        $detail = $this->explainer->explain($flag, new FlagContext('u1'));

        $this->assertSame(EvaluationReason::PercentageRollout, $detail->reason);
        $this->assertNotNull($detail->bucket);
        $this->assertSame('userId', $detail->bucketBy);
    }

    public function test_first_match_wins_second_rule_index_is_1(): void
    {
        $flag = $this->flag(enabled: true, rules: [
            new FlagRule(type: FlagRule::TYPE_USERS, users: ['other-user']),
            new FlagRule(type: FlagRule::TYPE_PERCENTAGE, percentage: 100),
        ]);

        $detail = $this->explainer->explain($flag, new FlagContext('u1'));

        $this->assertSame(1, $detail->matchedRuleIndex);
        $this->assertSame(EvaluationReason::PercentageRollout, $detail->reason);
    }

    public function test_no_rules_enabled_returns_default_reason(): void
    {
        $flag   = $this->flag(enabled: true, rules: []);
        $detail = $this->explainer->explain($flag, new FlagContext('u1'));

        $this->assertSame(EvaluationReason::Default, $detail->reason);
        $this->assertTrue($detail->value->asBool());
    }

    public function test_rules_present_but_none_match_returns_default_with_false(): void
    {
        $flag = $this->flag(enabled: true, rules: [
            new FlagRule(type: FlagRule::TYPE_USERS, users: ['other-user']),
        ]);

        $detail = $this->explainer->explain($flag, new FlagContext('u1'));

        $this->assertSame(EvaluationReason::Default, $detail->reason);
        $this->assertFalse($detail->value->asBool());
    }

    // ── Schedule ──

    public function test_outside_schedule_window_returns_schedule_window_reason(): void
    {
        $future = new \DateTimeImmutable('+1 hour');
        $flag   = $this->flag(enabled: true, schedule: new RolloutSchedule(
            enableAt: $future,
        ));

        $detail = $this->explainer->explain($flag, new FlagContext('u1'));

        $this->assertSame(EvaluationReason::ScheduleWindow, $detail->reason);
    }

    public function test_in_ramp_returns_schedule_ramp_reason_with_bucket(): void
    {
        $past = new \DateTimeImmutable('-1 hour');
        $flag = $this->flag(enabled: true, schedule: new RolloutSchedule(
            enableAt: $past,
            stops: [['at' => $past, 'percentage' => 100]],
        ));

        $detail = $this->explainer->explain($flag, new FlagContext('u1'));

        $this->assertSame(EvaluationReason::ScheduleRamp, $detail->reason);
        $this->assertNotNull($detail->bucket);
    }

    // ── Variants ──

    public function test_variant_flag_returns_variant_name(): void
    {
        $flag = $this->flag(enabled: true, rules: [], variants: ['treatment' => 100]);

        $detail = $this->explainer->explain($flag, new FlagContext('u1'));

        $this->assertSame('treatment', $detail->variant);
    }

    // ── Error handling ──

    public function test_throwable_during_explain_returns_error_reason(): void
    {
        $resolver = $this->createMock(FlagResolverInterface::class);
        $resolver->method('resolve')->willThrowException(new \RuntimeException('boom'));

        $explainer = new EvaluationExplainer(flags: $resolver);
        $flag      = $this->flag(enabled: true, prerequisites: [
            new Prerequisite('prereq', FlagValue::bool(true)),
        ]);

        $detail = $explainer->explain($flag, new FlagContext('u1'));

        $this->assertSame(EvaluationReason::Error, $detail->reason);
        $this->assertSame('boom', $detail->errorMessage);
    }

    // ── Parity with evaluator ──

    public function test_explain_value_matches_evaluator_for_enabled_no_rules(): void
    {
        $flag = $this->flag(enabled: true, rules: []);
        $ctx  = new FlagContext('u1');

        $eval = new \Vortos\FeatureFlags\FlagEvaluator();
        $this->assertSame($eval->evaluate($flag, $ctx), $this->explainer->explain($flag, $ctx)->value->asBool());
    }

    public function test_explain_value_matches_evaluator_for_disabled(): void
    {
        $flag = $this->flag(enabled: false);
        $ctx  = new FlagContext('u1');

        $eval = new \Vortos\FeatureFlags\FlagEvaluator();
        $this->assertSame($eval->evaluate($flag, $ctx), $this->explainer->explain($flag, $ctx)->value->asBool());
    }

    public function test_explain_value_matches_evaluator_for_percentage(): void
    {
        $flag = $this->flag(enabled: true, rules: [new FlagRule(type: FlagRule::TYPE_PERCENTAGE, percentage: 50)]);
        $ctx  = new FlagContext('u1');

        $eval = new \Vortos\FeatureFlags\FlagEvaluator();
        $this->assertSame($eval->evaluate($flag, $ctx), $this->explainer->explain($flag, $ctx)->value->asBool());
    }

    // ── toArray serialization ──

    public function test_detail_to_array_includes_all_set_fields(): void
    {
        $flag   = $this->flag(enabled: true, rules: [new FlagRule(type: FlagRule::TYPE_PERCENTAGE, percentage: 100)]);
        $detail = $this->explainer->explain($flag, new FlagContext('u1'));
        $arr    = $detail->toArray();

        $this->assertArrayHasKey('flag', $arr);
        $this->assertArrayHasKey('value', $arr);
        $this->assertArrayHasKey('variant', $arr);
        $this->assertArrayHasKey('reason', $arr);
        $this->assertSame('PERCENTAGE_ROLLOUT', $arr['reason']);
    }

    public function test_detail_to_array_omits_null_fields(): void
    {
        $flag   = $this->flag(enabled: false);
        $detail = $this->explainer->explain($flag, new FlagContext('u1'));
        $arr    = $detail->toArray();

        $this->assertArrayNotHasKey('matched_rule_index', $arr);
        $this->assertArrayNotHasKey('bucket', $arr);
        $this->assertArrayNotHasKey('error_message', $arr);
    }

    // ── Helpers ──

    private function flag(
        string $name = 'test-flag',
        bool $enabled = true,
        array $rules = [],
        ?array $variants = null,
        FlagLifecycleState $lifecycle = FlagLifecycleState::Active,
        array $prerequisites = [],
        ?RolloutSchedule $schedule = null,
    ): FeatureFlag {
        return new FeatureFlag(
            id:           'id-1',
            name:         $name,
            description:  'test',
            enabled:      $enabled,
            rules:        $rules,
            variants:     $variants,
            createdAt:    new \DateTimeImmutable(),
            updatedAt:    new \DateTimeImmutable(),
            lifecycle:    $lifecycle,
            prerequisites: $prerequisites,
            schedule:     $schedule,
        );
    }
}
