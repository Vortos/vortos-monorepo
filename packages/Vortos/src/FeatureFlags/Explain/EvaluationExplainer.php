<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Explain;

use Psr\Clock\ClockInterface;
use Vortos\FeatureFlags\Authz\FlagAuthzGateInterface;
use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\FlagContext;
use Vortos\FeatureFlags\FlagLifecycleState;
use Vortos\FeatureFlags\FlagResolverInterface;
use Vortos\FeatureFlags\FlagRule;
use Vortos\FeatureFlags\FlagValue;
use Vortos\FeatureFlags\FlagValueType;
use Vortos\FeatureFlags\NullFlagResolver;
use Vortos\FeatureFlags\NullSegmentResolver;
use Vortos\FeatureFlags\SegmentResolverInterface;
use Vortos\FeatureFlags\SystemClock;
use Vortos\FeatureFlags\Targeting\Bucketing;
use Vortos\FeatureFlags\Targeting\OperatorEvaluator;

/**
 * Mirrors {@see \Vortos\FeatureFlags\FlagEvaluator} logic but produces an
 * {@see FlagEvaluationDetail} trace explaining *why* the value was chosen.
 *
 * This is a separate class — not a mode flag on the evaluator — so the hot path
 * remains allocation-free and branch-free. The explain path is only invoked by the
 * management preview endpoint and the future rule-builder UI.
 */
final class EvaluationExplainer
{
    private readonly OperatorEvaluator $operators;
    private readonly SegmentResolverInterface $segments;
    private readonly FlagResolverInterface $flags;
    private readonly ClockInterface $clock;

    public function __construct(
        ?OperatorEvaluator $operators = null,
        ?SegmentResolverInterface $segments = null,
        ?FlagResolverInterface $flags = null,
        ?ClockInterface $clock = null,
        private readonly ?FlagAuthzGateInterface $authz = null,
    ) {
        $this->operators = $operators ?? new OperatorEvaluator();
        $this->segments  = $segments ?? new NullSegmentResolver();
        $this->flags     = $flags ?? new NullFlagResolver();
        $this->clock     = $clock ?? new SystemClock();
    }

    public function explain(FeatureFlag $flag, FlagContext $context): FlagEvaluationDetail
    {
        try {
            return $this->doExplain($flag, $context, 0);
        } catch (\Throwable $e) {
            return new FlagEvaluationDetail(
                flagName:     $flag->name,
                value:        $flag->defaultValue(),
                variant:      'control',
                reason:       EvaluationReason::Error,
                errorMessage: $e->getMessage(),
            );
        }
    }

    private function doExplain(FeatureFlag $flag, FlagContext $context, int $depth): FlagEvaluationDetail
    {
        if ($flag->lifecycle === FlagLifecycleState::Archived) {
            return $this->detail($flag, $flag->defaultValue(), 'control', EvaluationReason::Archived);
        }

        if (!$flag->enabled) {
            return $this->detail($flag, $flag->defaultValue(), 'control', EvaluationReason::Disabled);
        }

        // Authz gate
        if ($this->authz !== null && !$this->authz->allows($flag, $context)) {
            return $this->detail($flag, $flag->defaultValue(), 'control', EvaluationReason::AuthzDenied);
        }

        // Prerequisites
        $prereqResult = $this->checkPrerequisites($flag, $context, $depth);
        if ($prereqResult !== null) {
            return $prereqResult;
        }

        // Schedule window
        $rampPercentage = null;
        if ($flag->schedule !== null) {
            $now = $this->clock->now();
            if (!$flag->schedule->isActiveAt($now)) {
                return $this->detail($flag, $flag->defaultValue(), 'control', EvaluationReason::ScheduleWindow);
            }
            $rampPercentage = $flag->schedule->percentageAt($now);
        }

        // Rule matching — first match wins
        if (!empty($flag->rules)) {
            foreach ($flag->rules as $index => $rule) {
                if ($this->matchesRule($rule, $flag, $context, 0)) {
                    $reason = $rule->type === FlagRule::TYPE_PERCENTAGE
                        ? EvaluationReason::PercentageRollout
                        : ($rule->type === FlagRule::TYPE_USERS ? EvaluationReason::TargetMatch : EvaluationReason::RuleMatch);

                    $bucket   = null;
                    $bucketBy = null;
                    if ($rule->type === FlagRule::TYPE_PERCENTAGE) {
                        $bucketBy = $flag->bucketBy;
                        $key      = $context->bucketingValue($flag->bucketBy);
                        $bucket   = $key !== null ? Bucketing::bucket($flag->name, $key) : null;
                    }

                    return new FlagEvaluationDetail(
                        flagName:             $flag->name,
                        value:                $this->resolveOnValue($flag),
                        variant:              $this->resolveVariant($flag, $context),
                        reason:               $reason,
                        matchedRuleIndex:     $index,
                        matchedRuleDescription: $this->describeRule($rule),
                        bucket:               $bucket,
                        bucketBy:             $bucketBy,
                    );
                }
            }
        }

        // Gradual ramp
        if ($rampPercentage !== null) {
            $key      = $context->bucketingValue($flag->bucketBy);
            $bucket   = $key !== null ? Bucketing::bucket($flag->name, $key) : null;
            $inRamp   = $bucket !== null && $bucket < $rampPercentage * 100;

            if ($inRamp) {
                return new FlagEvaluationDetail(
                    flagName: $flag->name,
                    value:    $this->resolveOnValue($flag),
                    variant:  $this->resolveVariant($flag, $context),
                    reason:   EvaluationReason::ScheduleRamp,
                    bucket:   $bucket,
                    bucketBy: $flag->bucketBy,
                );
            }

            return new FlagEvaluationDetail(
                flagName: $flag->name,
                value:    $flag->defaultValue(),
                variant:  'control',
                reason:   EvaluationReason::ScheduleRamp,
                bucket:   $bucket,
                bucketBy: $flag->bucketBy,
            );
        }

        // No rules and no ramp — flag is simply on
        if (empty($flag->rules)) {
            return $this->detail($flag, $this->resolveOnValue($flag), $this->resolveVariant($flag, $context), EvaluationReason::Default);
        }

        // No rule matched — fallthrough to default
        return $this->detail($flag, $flag->defaultValue(), 'control', EvaluationReason::Default);
    }

    private function checkPrerequisites(FeatureFlag $flag, FlagContext $context, int $depth): ?FlagEvaluationDetail
    {
        if ($flag->prerequisites === []) {
            return null;
        }

        if ($depth >= 8) {
            return $this->detail($flag, $flag->defaultValue(), 'control', EvaluationReason::PrerequisiteFailed, prerequisiteFlag: '(depth limit)');
        }

        foreach ($flag->prerequisites as $prerequisite) {
            $target = $this->flags->resolve($prerequisite->flag);
            if ($target === null) {
                return $this->detail($flag, $flag->defaultValue(), 'control', EvaluationReason::PrerequisiteFailed, prerequisiteFlag: $prerequisite->flag);
            }

            $inner = $this->doExplain($target, $context, $depth + 1);
            if ($inner->value->raw() !== $prerequisite->expectedValue->raw()) {
                return $this->detail($flag, $flag->defaultValue(), 'control', EvaluationReason::PrerequisiteFailed, prerequisiteFlag: $prerequisite->flag);
            }
        }

        return null;
    }

    private function resolveOnValue(FeatureFlag $flag): FlagValue
    {
        return match ($flag->valueType) {
            FlagValueType::Bool   => FlagValue::bool(true),
            FlagValueType::Json   => FlagValue::json($flag->payload ?? $flag->defaultValue()->asJson()),
            FlagValueType::String,
            FlagValueType::Number => $flag->defaultValue(),
        };
    }

    private function resolveVariant(FeatureFlag $flag, FlagContext $context): string
    {
        if (!$flag->isVariant() || !$flag->enabled || empty($flag->variants)) {
            return 'control';
        }

        // Per-variant targeting overrides
        if ($flag->variantRules !== null) {
            foreach ($flag->variantRules as $variant => $rules) {
                foreach ($rules as $rule) {
                    if ($this->matchesRule($rule, $flag, $context, 0)) {
                        return $variant;
                    }
                }
            }
        }

        $key = $context->bucketingValue($flag->bucketBy);
        if ($key === null) {
            return 'control';
        }

        $bucket     = Bucketing::bucket($flag->name . "\x00variant", $key);
        $cumulative = 0;

        foreach ($flag->variants as $variant => $percentage) {
            $cumulative += $percentage * 100;
            if ($bucket < $cumulative) {
                return $variant;
            }
        }

        return 'control';
    }

    private function matchesRule(FlagRule $rule, FeatureFlag $flag, FlagContext $context, int $depth): bool
    {
        if ($depth >= 8) {
            return false;
        }

        return match ($rule->type) {
            FlagRule::TYPE_USERS      => $context->userId !== null && in_array($context->userId, $rule->users, true),
            FlagRule::TYPE_ATTRIBUTE  => $this->operators->matches($rule->operator ?? '', $this->readZone($rule, $context), $rule->value),
            FlagRule::TYPE_PERCENTAGE => $this->matchesPercentage($rule, $flag, $context),
            FlagRule::TYPE_GROUP      => $this->matchesGroup($rule, $flag, $context, $depth),
            FlagRule::TYPE_SEGMENT    => $this->matchesSegment($rule, $flag, $context, $depth),
            default                   => false,
        };
    }

    private function matchesPercentage(FlagRule $rule, FeatureFlag $flag, FlagContext $context): bool
    {
        $key = $context->bucketingValue($flag->bucketBy);
        return $key !== null && Bucketing::bucket($flag->name, $key) < $rule->percentage * 100;
    }

    private function matchesGroup(FlagRule $rule, FeatureFlag $flag, FlagContext $context, int $depth): bool
    {
        if ($rule->children === []) {
            return false;
        }

        $isAnd = $rule->combinator === FlagRule::CMB_AND;

        foreach ($rule->children as $child) {
            $childMatches = $this->matchesRule($child, $flag, $context, $depth + 1);

            if ($isAnd && !$childMatches) {
                return false;
            }
            if (!$isAnd && $childMatches) {
                return true;
            }
        }

        return $isAnd;
    }

    private function matchesSegment(FlagRule $rule, FeatureFlag $flag, FlagContext $context, int $depth): bool
    {
        $segment = $this->segments->resolve($rule->segment ?? '');
        if ($segment === null) {
            return false;
        }

        foreach ($segment->rules as $segmentRule) {
            if ($this->matchesRule($segmentRule, $flag, $context, $depth + 1)) {
                return true;
            }
        }

        return false;
    }

    private function readZone(FlagRule $rule, FlagContext $context): mixed
    {
        $key = $rule->attribute ?? '';

        return match ($rule->zone) {
            FlagRule::ZONE_TRUSTED   => $context->getTrusted($key),
            FlagRule::ZONE_UNTRUSTED => $context->getUntrusted($key),
            default                  => $context->getAttribute($key),
        };
    }

    private function describeRule(FlagRule $rule): string
    {
        return match ($rule->type) {
            FlagRule::TYPE_USERS      => sprintf('users(%s)', implode(',', array_slice($rule->users, 0, 5))),
            FlagRule::TYPE_ATTRIBUTE  => sprintf('%s %s %s', $rule->attribute ?? '?', $rule->operator ?? '?', is_array($rule->value) ? json_encode($rule->value) : (string) ($rule->value ?? '')),
            FlagRule::TYPE_PERCENTAGE => sprintf('percentage(%d%%)', $rule->percentage),
            FlagRule::TYPE_GROUP      => sprintf('group(%s, %d children)', $rule->combinator ?? 'OR', count($rule->children)),
            FlagRule::TYPE_SEGMENT    => sprintf('segment(%s)', $rule->segment ?? '?'),
            default                   => $rule->type,
        };
    }

    private function detail(
        FeatureFlag $flag,
        FlagValue $value,
        string $variant,
        EvaluationReason $reason,
        ?string $prerequisiteFlag = null,
    ): FlagEvaluationDetail {
        return new FlagEvaluationDetail(
            flagName:         $flag->name,
            value:            $value,
            variant:          $variant,
            reason:           $reason,
            prerequisiteFlag: $prerequisiteFlag,
        );
    }
}
