<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags;

use Psr\Clock\ClockInterface;
use Vortos\FeatureFlags\Layer\LayerEvaluator;
use Vortos\FeatureFlags\Targeting\Bucketing;
use Vortos\FeatureFlags\Targeting\OperatorEvaluator;

final class FlagEvaluator
{
    /** Hard cap on rule-group nesting — defends against pathological/malicious trees. */
    public const MAX_GROUP_DEPTH = 8;

    private readonly OperatorEvaluator $operators;
    private readonly SegmentResolverInterface $segments;
    private readonly FlagResolverInterface $flags;
    private readonly ClockInterface $clock;
    private readonly ?LayerEvaluator $layers;

    public function __construct(
        ?OperatorEvaluator $operators = null,
        ?SegmentResolverInterface $segments = null,
        ?FlagResolverInterface $flags = null,
        ?ClockInterface $clock = null,
        ?LayerEvaluator $layers = null,
    ) {
        $this->operators = $operators ?? new OperatorEvaluator();
        $this->segments  = $segments ?? new NullSegmentResolver();
        $this->flags     = $flags ?? new NullFlagResolver();
        $this->clock     = $clock ?? new SystemClock();
        $this->layers    = $layers;
    }

    /**
     * Whether the flag is "on" (the boolean gate) for the context.
     * Safe-defaults to the flag's boolean default if evaluation cannot complete.
     */
    public function evaluate(FeatureFlag $flag, FlagContext $context): bool
    {
        try {
            return $this->isOn($flag, $context, 0);
        } catch (\Throwable) {
            return $flag->defaultValue()->asBool();
        }
    }

    /**
     * The typed value served for the context. Returns the flag's safe default whenever
     * the flag is off, unmatched, or evaluation throws — this method never raises.
     */
    public function evaluateValue(FeatureFlag $flag, FlagContext $context): FlagValue
    {
        try {
            return $this->resolveValue($flag, $context, 0);
        } catch (\Throwable) {
            return $flag->defaultValue();
        }
    }

    /**
     * The remote-config payload delivered when the flag is on, else null.
     * @return array<array-key,mixed>|null
     */
    public function evaluatePayload(FeatureFlag $flag, FlagContext $context): ?array
    {
        try {
            return $this->isOn($flag, $context, 0) ? $flag->payload : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function evaluateVariant(FeatureFlag $flag, FlagContext $context): string
    {
        $key = $context->bucketingValue($flag->bucketBy);

        if (!$flag->enabled || empty($flag->variants)) {
            return 'control';
        }

        // Per-variant targeting overrides win over weighted assignment.
        $forced = $this->forcedVariant($flag, $context);
        if ($forced !== null) {
            return $forced;
        }

        if ($key === null) {
            return 'control';
        }

        // Decorrelate variant assignment from the rollout bucket via a distinct salt,
        // so being in the rollout doesn't bias which variant a user gets. Iterating in a
        // stable key order keeps assignment sticky: raising one weight only moves users
        // at the shifted boundary, never reshuffles the whole population.
        $bucket     = Bucketing::bucket($flag->name . "\x00variant", $key);
        $cumulative = 0;

        foreach ($flag->variants as $variant => $percentage) {
            $cumulative += $percentage * 100; // variant weights are 0..100 → 0..10000 space
            if ($bucket < $cumulative) {
                return $variant;
            }
        }

        return 'control';
    }

    /** Resolve a variant forced by a per-variant override rule, or null if none match. */
    private function forcedVariant(FeatureFlag $flag, FlagContext $context): ?string
    {
        if ($flag->variantRules === null) {
            return null;
        }

        foreach ($flag->variantRules as $variant => $rules) {
            foreach ($rules as $rule) {
                if ($this->matchesRule($rule, $flag, $context, 0)) {
                    return $variant;
                }
            }
        }

        return null;
    }

    private function resolveValue(FeatureFlag $flag, FlagContext $context, int $depth): FlagValue
    {
        if (!$this->isOn($flag, $context, $depth)) {
            return $flag->defaultValue();
        }

        return match ($flag->valueType) {
            FlagValueType::Bool   => FlagValue::bool(true),
            FlagValueType::Json   => FlagValue::json($flag->payload ?? $flag->defaultValue()->asJson()),
            // String/Number on-values arrive with variants; without one the declared
            // default is the single served value.
            FlagValueType::String,
            FlagValueType::Number => $flag->defaultValue(),
        };
    }

    private function isOn(FeatureFlag $flag, FlagContext $context, int $depth): bool
    {
        if (!$flag->enabled) {
            return false;
        }

        // Prerequisites gate the whole flag: all must hold for the same context.
        if (!$this->prerequisitesMet($flag, $context, $depth)) {
            return false;
        }

        // Scheduled window + gradual ramp, resolved against the injected clock.
        $rampPercentage = null;
        if ($flag->schedule !== null) {
            $now = $this->clock->now();
            if (!$flag->schedule->isActiveAt($now)) {
                return false; // outside the scheduled on/off window
            }
            $rampPercentage = $flag->schedule->percentageAt($now);
        }

        // No explicit rules and no ramp constraint → simply on (within any window).
        if (empty($flag->rules) && $rampPercentage === null) {
            return true;
        }

        // Ordered, first-match-wins / OR across top-level rules (legacy semantics preserved).
        foreach ($flag->rules as $rule) {
            if ($this->matchesRule($rule, $flag, $context, 0)) {
                return true;
            }
        }

        // Gradual ramp acts as an additional rollout gate, OR'd with explicit rules.
        if ($rampPercentage !== null) {
            return $this->inRamp($flag, $context, $rampPercentage);
        }

        return false;
    }

    private function inRamp(FeatureFlag $flag, FlagContext $context, int $percentage): bool
    {
        if ($percentage <= 0) {
            return false;
        }

        $key = $context->bucketingValue($flag->bucketBy);
        if ($key === null) {
            return false;
        }

        // Layered flag: mutual-exclusion check replaces independent rollout salt.
        // Safe-default: if LayerEvaluator is absent or layer config is missing,
        // the flag does NOT fire (never silently promotes into an experiment).
        if ($flag->layerId !== null) {
            return $this->layers !== null && $this->layers->isInSlice($flag, $context);
        }

        return Bucketing::bucket($flag->name, $key) < $percentage * 100;
    }

    /**
     * Every prerequisite flag must resolve to its expected value for this context.
     * Missing prerequisite → unmet (safe). Depth-guarded against cyclic config (which is
     * also rejected at write time by the validator).
     */
    private function prerequisitesMet(FeatureFlag $flag, FlagContext $context, int $depth): bool
    {
        if ($flag->prerequisites === []) {
            return true;
        }

        if ($depth >= self::MAX_GROUP_DEPTH) {
            return false;
        }

        foreach ($flag->prerequisites as $prerequisite) {
            $target = $this->flags->resolve($prerequisite->flag);
            if ($target === null) {
                return false;
            }

            $actual = $this->resolveValue($target, $context, $depth + 1);
            if ($actual->raw() !== $prerequisite->expectedValue->raw()) {
                return false;
            }
        }

        return true;
    }

    private function matchesRule(FlagRule $rule, FeatureFlag $flag, FlagContext $context, int $depth): bool
    {
        return match ($rule->type) {
            FlagRule::TYPE_USERS      => $this->matchesUsers($rule, $context),
            FlagRule::TYPE_ATTRIBUTE  => $this->matchesAttribute($rule, $context),
            FlagRule::TYPE_PERCENTAGE => $this->matchesPercentage($rule, $flag, $context),
            FlagRule::TYPE_GROUP      => $this->matchesGroup($rule, $flag, $context, $depth),
            FlagRule::TYPE_SEGMENT    => $this->matchesSegment($rule, $flag, $context, $depth),
            default                   => false,
        };
    }

    /**
     * A segment matches when the context matches any of its rules (first-match-wins / OR,
     * the same semantics as a flag's top-level rules). A missing/deleted segment is a safe
     * no-match. The depth guard also protects against a (disallowed) segment→segment cycle.
     */
    private function matchesSegment(FlagRule $rule, FeatureFlag $flag, FlagContext $context, int $depth): bool
    {
        if ($depth >= self::MAX_GROUP_DEPTH) {
            return false;
        }

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

    private function matchesGroup(FlagRule $rule, FeatureFlag $flag, FlagContext $context, int $depth): bool
    {
        // Depth guard: a too-deep (or cyclic, were that possible) tree is a safe no-match.
        if ($depth >= self::MAX_GROUP_DEPTH || $rule->children === []) {
            return false;
        }

        $isAnd = $rule->combinator === FlagRule::CMB_AND;

        foreach ($rule->children as $child) {
            $childMatches = $this->matchesRule($child, $flag, $context, $depth + 1);

            if ($isAnd && !$childMatches) {
                return false; // AND: any failure fails the group
            }
            if (!$isAnd && $childMatches) {
                return true;  // OR: any success passes the group
            }
        }

        // AND with no failures → true; OR with no successes → false.
        return $isAnd;
    }

    private function matchesUsers(FlagRule $rule, FlagContext $context): bool
    {
        return $context->userId !== null && in_array($context->userId, $rule->users, true);
    }

    private function matchesPercentage(FlagRule $rule, FeatureFlag $flag, FlagContext $context): bool
    {
        $key = $context->bucketingValue($flag->bucketBy);

        if ($key === null) {
            return false;
        }

        // Layered flag: layer slice replaces independent per-flag bucket. The percentage
        // value in the rule is ignored — slice membership IS the rollout criterion.
        // Safe-defaults to false if the layer evaluator is absent or config is missing.
        if ($flag->layerId !== null) {
            return $this->layers !== null && $this->layers->isInSlice($flag, $context);
        }

        // Percentage is 0..100; bucket space is 0..9999 → multiply by 100.
        return Bucketing::bucket($flag->name, $key) < $rule->percentage * 100;
    }

    private function matchesAttribute(FlagRule $rule, FlagContext $context): bool
    {
        $actual = $this->readZone($rule, $context);

        return $this->operators->matches($rule->operator ?? '', $actual, $rule->value);
    }

    /** Read the attribute from the trust zone the rule declares (default: any). */
    private function readZone(FlagRule $rule, FlagContext $context): mixed
    {
        $key = $rule->attribute ?? '';

        return match ($rule->zone) {
            FlagRule::ZONE_TRUSTED   => $context->getTrusted($key),
            FlagRule::ZONE_UNTRUSTED => $context->getUntrusted($key),
            default                  => $context->getAttribute($key),
        };
    }
}
