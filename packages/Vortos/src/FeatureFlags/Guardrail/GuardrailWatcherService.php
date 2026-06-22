<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Guardrail;

use Psr\Clock\ClockInterface;
use Vortos\FeatureFlags\Application\FlagWriteService;
use Vortos\FeatureFlags\Guardrail\Domain\Event\GuardrailBreachRecordedEvent;
use Vortos\FeatureFlags\Guardrail\Domain\Event\GuardrailPendingAckEvent;
use Vortos\FeatureFlags\Guardrail\Domain\Event\GuardrailResolvedEvent;
use Vortos\FeatureFlags\Guardrail\Domain\Event\GuardrailTriggeredEvent;
use Vortos\FeatureFlags\Guardrail\MetricSource\GuardrailMetricSourceInterface;
use Vortos\FeatureFlags\Guardrail\Storage\GuardrailPolicyStorageInterface;
use Vortos\FeatureFlags\FlagScopeContext;
use Vortos\FeatureFlags\RolloutSchedule;
use Vortos\FeatureFlags\Guardrail\Support\GuardrailEventEnvelopeFactory;
use Vortos\FeatureFlags\Storage\FlagStorageInterface;
use Vortos\Messaging\Contract\EventBusInterface;

/**
 * Block 15 — the guardrail evaluation loop. Driven on a worker/cron tick by
 * {@see \Vortos\FeatureFlags\Command\FlagsEvaluateGuardrailsCommand}.
 *
 * For each due policy it evaluates the (possibly composite) condition tree against the
 * metric source, applies a debounce (`consecutiveWindows` breaches in a row before
 * firing), honours a post-trigger cooldown, and — when a breach is confirmed — performs
 * the configured action (`disable` or `pause_ramp`) through {@see FlagWriteService} so the
 * mutation is audited like any other. A recovered metric resolves a triggered policy.
 *
 * Unknown metrics (source returns null) never trip a guardrail — they leave the breach
 * counter untouched, so a metrics outage can never auto-disable a flag.
 */
final class GuardrailWatcherService
{
    public function __construct(
        private readonly GuardrailPolicyStorageInterface $storage,
        private readonly GuardrailConditionEvaluator $conditionEvaluator,
        private readonly GuardrailMetricSourceInterface $metricSource,
        private readonly FlagWriteService $writeService,
        private readonly FlagStorageInterface $flagStorage,
        private readonly FlagScopeContext $scopeContext,
        private readonly EventBusInterface $eventBus,
        private readonly ClockInterface $clock,
        private readonly int $batchSize = 100,
    ) {}

    /** Run one evaluation sweep. Returns the number of policies evaluated. */
    public function evaluate(): int
    {
        $now      = $this->clock->now();
        $policies = $this->storage->findDueForEvaluation($now, $this->batchSize);

        $evaluated = 0;
        foreach ($policies as $policy) {
            $this->evaluatePolicy($policy, $now);
            $evaluated++;
        }

        return $evaluated;
    }

    private function evaluatePolicy(GuardrailPolicy $policy, \DateTimeImmutable $now): void
    {
        if ($policy->status === GuardrailStatus::Triggered->value && $policy->isInCooldown($now)) {
            return;
        }

        $breach = $this->evaluateConditions($policy, $now);

        if ($breach === null) {
            // Unknown — record only that we looked; never touch the breach counter.
            $this->persist($policy, $policy->status, $policy->consecutiveBreachCount, $now, $policy->triggeredAt, $policy->resolvedAt);
            return;
        }

        if ($breach === false) {
            $this->handleClear($policy, $now);
            return;
        }

        $this->handleBreach($policy, $now);
    }

    private function handleClear(GuardrailPolicy $policy, \DateTimeImmutable $now): void
    {
        if ($policy->status === GuardrailStatus::Triggered->value) {
            if ($policy->ackRequired) {
                // Metric is clear but policy requires human acknowledgement — stay Triggered.
                $this->persist($policy, GuardrailStatus::Triggered->value, 0, $now, $policy->triggeredAt, $policy->resolvedAt);
                $this->emit(new GuardrailPendingAckEvent($policy->id, $policy->flagName, $policy->environment, $now));
                return;
            }

            $this->persist($policy, GuardrailStatus::Resolved->value, 0, $now, null, $now);
            $this->emit(new GuardrailResolvedEvent($policy->id, $policy->flagName, $policy->environment, $now));
            return;
        }

        $this->persist($policy, GuardrailStatus::Watching->value, 0, $now, $policy->triggeredAt, $policy->resolvedAt);
    }

    private function handleBreach(GuardrailPolicy $policy, \DateTimeImmutable $now): void
    {
        $count = $policy->consecutiveBreachCount + 1;

        $this->emit(new GuardrailBreachRecordedEvent(
            $policy->id,
            $policy->flagName,
            $policy->environment,
            $count,
            $policy->consecutiveWindows,
            $now,
        ));

        if ($count < $policy->consecutiveWindows) {
            $this->persist($policy, GuardrailStatus::Watching->value, $count, $now, $policy->triggeredAt, $policy->resolvedAt);
            return;
        }

        $observed = $this->representativeValue($policy, $now);
        $this->triggerAction($policy, $now);
        $this->persist($policy, GuardrailStatus::Triggered->value, 0, $now, $now, null);
        $this->emit(new GuardrailTriggeredEvent(
            $policy->id,
            $policy->flagName,
            $policy->environment,
            $policy->action->value,
            $observed,
            $now,
        ));
    }

    private function triggerAction(GuardrailPolicy $policy, \DateTimeImmutable $now): void
    {
        $actor = 'system:guardrail:' . $policy->id;

        $this->scopeContext->runAs($policy->environment, function () use ($policy, $actor, $now): void {
            if ($policy->action === GuardrailAction::Disable) {
                $this->writeService->disable($policy->flagName, $actor, 'guardrail breach — automatic kill switch');
                return;
            }

            // PauseRamp — freeze the ramp at the configured floor (or the current %).
            $flag     = $this->flagStorage->findByName($policy->flagName);
            $schedule = $flag?->schedule;

            if ($schedule === null && $policy->pauseRampTargetPct === null) {
                $this->writeService->disable($policy->flagName, $actor, 'guardrail breach — no ramp to pause, disabling');
                return;
            }

            $target = $policy->pauseRampTargetPct ?? $schedule?->percentageAt($now) ?? 0;
            $frozen = new RolloutSchedule(
                enableAt:  $schedule?->enableAt,
                disableAt: null,
                stops:     [['at' => $now, 'percentage' => $target]],
            );
            $this->writeService->schedule($policy->flagName, $frozen, $actor, 'guardrail breach — ramp paused');
        });
    }

    /** Evaluate every top-level condition and combine with OR semantics. */
    private function evaluateConditions(GuardrailPolicy $policy, \DateTimeImmutable $now): ?bool
    {
        $results = [];
        foreach ($policy->conditions as $condition) {
            $results[] = $this->conditionEvaluator->evaluate(
                $condition,
                $policy->flagName,
                $policy->environment,
                $policy->windowSeconds,
            );
        }

        if (in_array(true, $results, true)) {
            return true;
        }
        if (in_array(null, $results, true)) {
            return null;
        }

        return false;
    }

    private function representativeValue(GuardrailPolicy $policy, \DateTimeImmutable $now): float
    {
        $leaf = $this->firstLeaf($policy->conditions);
        if ($leaf === null || $leaf->metricKind === null) {
            return 0.0;
        }

        $value = $this->metricSource->query(new GuardrailMetricQuery(
            metricKind:       $leaf->metricKind,
            flagName:         $policy->flagName,
            environment:      $policy->environment,
            windowSeconds:    $policy->windowSeconds,
            customMetricName: $leaf->customMetricName,
        ));

        return $value ?? 0.0;
    }

    /**
     * @param GuardrailCondition[] $conditions
     */
    private function firstLeaf(array $conditions): ?GuardrailCondition
    {
        foreach ($conditions as $condition) {
            if (!$condition->isGroup()) {
                return $condition;
            }
            $leaf = $this->firstLeaf($condition->children);
            if ($leaf !== null) {
                return $leaf;
            }
        }

        return null;
    }

    private function persist(
        GuardrailPolicy $policy,
        string $status,
        int $count,
        \DateTimeImmutable $lastEvaluatedAt,
        ?\DateTimeImmutable $triggeredAt,
        ?\DateTimeImmutable $resolvedAt,
    ): void {
        $this->storage->save(new GuardrailPolicy(
            id:                     $policy->id,
            flagName:               $policy->flagName,
            projectId:              $policy->projectId,
            environment:            $policy->environment,
            status:                 $status,
            action:                 $policy->action,
            pauseRampTargetPct:     $policy->pauseRampTargetPct,
            consecutiveWindows:     $policy->consecutiveWindows,
            windowSeconds:          $policy->windowSeconds,
            cooldownSeconds:        $policy->cooldownSeconds,
            enabled:                $policy->enabled,
            consecutiveBreachCount: $count,
            lastEvaluatedAt:        $lastEvaluatedAt,
            triggeredAt:            $triggeredAt,
            resolvedAt:             $resolvedAt,
            createdAt:              $policy->createdAt,
            createdBy:              $policy->createdBy,
            conditions:             $policy->conditions,
            ackRequired:            $policy->ackRequired,
        ));
    }

    private function emit(object $payload): void
    {
        $this->eventBus->dispatch(GuardrailEventEnvelopeFactory::wrap($payload, $this->clock->now()));
    }
}
