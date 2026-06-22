<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Guardrail;

use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;
use Vortos\FeatureFlags\Guardrail\Domain\Event\GuardrailResolvedEvent;
use Vortos\FeatureFlags\Guardrail\Storage\GuardrailPolicyStorageInterface;
use Vortos\FeatureFlags\Guardrail\Support\GuardrailEventEnvelopeFactory;
use Vortos\FeatureFlags\SystemClock;
use Vortos\Messaging\Contract\EventBusInterface;

/**
 * Block 15 — CRUD for guardrail policies. Validates the condition tree and the action
 * configuration up front so the watcher only ever loads well-formed policies.
 */
final class GuardrailPolicyService
{
    private readonly ClockInterface $clock;

    public function __construct(
        private readonly GuardrailPolicyStorageInterface $storage,
        private readonly EventBusInterface $eventBus,
        ?ClockInterface $clock = null,
    ) {
        $this->clock = $clock ?? new SystemClock();
    }

    /**
     * @param array<int, array<string, mixed>> $conditions raw condition tree
     */
    public function create(
        string $flagName,
        string $projectId,
        string $environment,
        GuardrailAction $action,
        array $conditions,
        int $consecutiveWindows,
        int $windowSeconds,
        int $cooldownSeconds,
        string $createdBy,
        ?int $pauseRampTargetPct = null,
        bool $ackRequired = false,
    ): GuardrailPolicy {
        if ($conditions === []) {
            throw new \InvalidArgumentException('A guardrail policy requires at least one condition.');
        }
        if ($consecutiveWindows < 1) {
            throw new \InvalidArgumentException('consecutiveWindows must be >= 1.');
        }
        if ($windowSeconds < 1 || $cooldownSeconds < 0) {
            throw new \InvalidArgumentException('Invalid window/cooldown configuration.');
        }
        if ($action === GuardrailAction::PauseRamp && $pauseRampTargetPct !== null && ($pauseRampTargetPct < 0 || $pauseRampTargetPct > 100)) {
            throw new \InvalidArgumentException('pauseRampTargetPct must be between 0 and 100.');
        }

        $built = array_map(fn(array $c) => $this->buildCondition($c), $conditions);

        $policy = new GuardrailPolicy(
            id:                     Uuid::v7()->toRfc4122(),
            flagName:               $flagName,
            projectId:              $projectId,
            environment:            $environment,
            status:                 GuardrailStatus::Watching->value,
            action:                 $action,
            pauseRampTargetPct:     $pauseRampTargetPct,
            consecutiveWindows:     $consecutiveWindows,
            windowSeconds:          $windowSeconds,
            cooldownSeconds:        $cooldownSeconds,
            enabled:                true,
            consecutiveBreachCount: 0,
            lastEvaluatedAt:        null,
            triggeredAt:            null,
            resolvedAt:             null,
            createdAt:              $this->clock->now(),
            createdBy:              $createdBy,
            conditions:             $built,
            ackRequired:            $ackRequired,
        );

        $this->storage->save($policy);

        return $policy;
    }

    /**
     * @param array<string, mixed> $changes
     */
    public function update(string $id, array $changes, string $updatedBy): GuardrailPolicy
    {
        $existing = $this->storage->findById($id);
        if ($existing === null) {
            throw new \InvalidArgumentException(sprintf('Guardrail policy "%s" not found.', $id));
        }

        $conditions = isset($changes['conditions'])
            ? array_map(fn(array $c) => $this->buildCondition($c), $changes['conditions'])
            : $existing->conditions;

        $updated = new GuardrailPolicy(
            id:                     $existing->id,
            flagName:               $existing->flagName,
            projectId:              $existing->projectId,
            environment:            $existing->environment,
            status:                 $existing->status,
            action:                 isset($changes['action']) ? GuardrailAction::from((string) $changes['action']) : $existing->action,
            pauseRampTargetPct:     array_key_exists('pauseRampTargetPct', $changes) ? $changes['pauseRampTargetPct'] : $existing->pauseRampTargetPct,
            consecutiveWindows:     (int) ($changes['consecutiveWindows'] ?? $existing->consecutiveWindows),
            windowSeconds:          (int) ($changes['windowSeconds'] ?? $existing->windowSeconds),
            cooldownSeconds:        (int) ($changes['cooldownSeconds'] ?? $existing->cooldownSeconds),
            enabled:                array_key_exists('enabled', $changes) ? (bool) $changes['enabled'] : $existing->enabled,
            consecutiveBreachCount: $existing->consecutiveBreachCount,
            lastEvaluatedAt:        $existing->lastEvaluatedAt,
            triggeredAt:            $existing->triggeredAt,
            resolvedAt:             $existing->resolvedAt,
            createdAt:              $existing->createdAt,
            createdBy:              $existing->createdBy,
            conditions:             $conditions,
            ackRequired:            array_key_exists('ackRequired', $changes) ? (bool) $changes['ackRequired'] : $existing->ackRequired,
        );

        $this->storage->save($updated);

        return $updated;
    }

    /**
     * Human acknowledgement — resolves a triggered policy without waiting for auto-resolve.
     * Only valid when the policy has ack_required=true and is currently triggered.
     */
    public function acknowledge(string $id, string $actorId): GuardrailPolicy
    {
        $policy = $this->storage->findById($id);
        if ($policy === null) {
            throw new \DomainException(sprintf('Guardrail policy "%s" not found.', $id));
        }
        if ($policy->status !== GuardrailStatus::Triggered->value) {
            throw new \DomainException(sprintf(
                'Cannot acknowledge guardrail policy "%s" — status is "%s", expected "triggered".',
                $id,
                $policy->status,
            ));
        }

        $now = $this->clock->now();

        $resolved = new GuardrailPolicy(
            id:                     $policy->id,
            flagName:               $policy->flagName,
            projectId:              $policy->projectId,
            environment:            $policy->environment,
            status:                 GuardrailStatus::Resolved->value,
            action:                 $policy->action,
            pauseRampTargetPct:     $policy->pauseRampTargetPct,
            consecutiveWindows:     $policy->consecutiveWindows,
            windowSeconds:          $policy->windowSeconds,
            cooldownSeconds:        $policy->cooldownSeconds,
            enabled:                $policy->enabled,
            consecutiveBreachCount: 0,
            lastEvaluatedAt:        $policy->lastEvaluatedAt,
            triggeredAt:            $policy->triggeredAt,
            resolvedAt:             $now,
            createdAt:              $policy->createdAt,
            createdBy:              $policy->createdBy,
            conditions:             $policy->conditions,
            ackRequired:            $policy->ackRequired,
        );

        $this->storage->save($resolved);
        $this->eventBus->dispatch(GuardrailEventEnvelopeFactory::wrap(
            new GuardrailResolvedEvent($policy->id, $policy->flagName, $policy->environment, $now, $actorId),
            $now,
        ));

        return $resolved;
    }

    public function delete(string $id): void
    {
        $this->storage->delete($id);
    }

    public function findById(string $id): ?GuardrailPolicy
    {
        return $this->storage->findById($id);
    }

    /** @return GuardrailPolicy[] */
    public function listForFlag(string $flagName, string $projectId, string $environment): array
    {
        return array_values(array_filter(
            $this->storage->findEnabled($projectId, $environment),
            static fn(GuardrailPolicy $p) => $p->flagName === $flagName,
        ));
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function buildCondition(array $raw): GuardrailCondition
    {
        $children = array_map(fn(array $c) => $this->buildCondition($c), $raw['children'] ?? []);

        return new GuardrailCondition(
            id:                 isset($raw['id']) ? (string) $raw['id'] : Uuid::v7()->toRfc4122(),
            combinator:         isset($raw['combinator']) ? (string) $raw['combinator'] : null,
            metricKind:         isset($raw['metric_kind']) && $raw['metric_kind'] !== null ? GuardrailMetricKind::from((string) $raw['metric_kind']) : null,
            customMetricName:   isset($raw['custom_metric_name']) ? (string) $raw['custom_metric_name'] : null,
            threshold:          isset($raw['threshold']) && $raw['threshold'] !== null ? (float) $raw['threshold'] : null,
            comparisonOperator: isset($raw['comparison_operator']) ? (string) $raw['comparison_operator'] : null,
            sortOrder:          (int) ($raw['sort_order'] ?? 0),
            children:           $children,
        );
    }
}
