<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Guardrail;

final readonly class GuardrailPolicy
{
    /**
     * @param GuardrailCondition[] $conditions at least one required
     */
    public function __construct(
        public string $id,
        public string $flagName,
        public string $projectId,
        public string $environment,
        public string $status,
        public GuardrailAction $action,
        public ?int $pauseRampTargetPct,
        public int $consecutiveWindows,
        public int $windowSeconds,
        public int $cooldownSeconds,
        public bool $enabled,
        public int $consecutiveBreachCount,
        public ?\DateTimeImmutable $lastEvaluatedAt,
        public ?\DateTimeImmutable $triggeredAt,
        public ?\DateTimeImmutable $resolvedAt,
        public \DateTimeImmutable $createdAt,
        public string $createdBy,
        public array $conditions,
        /** When true the watcher will not auto-resolve after metric recovery — a human must call /ack. */
        public bool $ackRequired = false,
    ) {}

    public function isInCooldown(\DateTimeImmutable $now): bool
    {
        if ($this->triggeredAt === null) {
            return false;
        }

        $cooldownEndsAt = $this->triggeredAt->modify('+' . $this->cooldownSeconds . ' seconds');

        return $now < $cooldownEndsAt;
    }

    public function toArray(): array
    {
        return [
            'id'                      => $this->id,
            'flag_name'               => $this->flagName,
            'project_id'              => $this->projectId,
            'environment'             => $this->environment,
            'status'                  => $this->status,
            'action'                  => $this->action->value,
            'pause_ramp_target_pct'   => $this->pauseRampTargetPct,
            'consecutive_windows'     => $this->consecutiveWindows,
            'window_seconds'          => $this->windowSeconds,
            'cooldown_seconds'        => $this->cooldownSeconds,
            'enabled'                 => $this->enabled,
            'consecutive_breach_count' => $this->consecutiveBreachCount,
            'last_evaluated_at'       => $this->lastEvaluatedAt?->format(\DateTimeInterface::ATOM),
            'triggered_at'            => $this->triggeredAt?->format(\DateTimeInterface::ATOM),
            'resolved_at'             => $this->resolvedAt?->format(\DateTimeInterface::ATOM),
            'created_at'              => $this->createdAt->format(\DateTimeInterface::ATOM),
            'created_by'              => $this->createdBy,
            'conditions'              => array_map(fn(GuardrailCondition $c) => $c->toArray(), $this->conditions),
            'ack_required'            => $this->ackRequired,
        ];
    }

    public static function fromArray(array $data): self
    {
        $conditions = is_string($data['conditions'])
            ? json_decode($data['conditions'], true, 512, JSON_THROW_ON_ERROR) ?? []
            : ($data['conditions'] ?? []);

        return new self(
            id:                     (string) $data['id'],
            flagName:               (string) $data['flag_name'],
            projectId:              (string) $data['project_id'],
            environment:            (string) $data['environment'],
            status:                 (string) $data['status'],
            action:                 GuardrailAction::from($data['action']),
            pauseRampTargetPct:     isset($data['pause_ramp_target_pct']) && $data['pause_ramp_target_pct'] !== null
                                        ? (int) $data['pause_ramp_target_pct']
                                        : null,
            consecutiveWindows:     (int) ($data['consecutive_windows'] ?? 2),
            windowSeconds:          (int) ($data['window_seconds'] ?? 300),
            cooldownSeconds:        (int) ($data['cooldown_seconds'] ?? 600),
            enabled:                (bool) ($data['enabled'] ?? true),
            consecutiveBreachCount: (int) ($data['consecutive_breach_count'] ?? 0),
            lastEvaluatedAt:        isset($data['last_evaluated_at']) && $data['last_evaluated_at'] !== null
                                        ? new \DateTimeImmutable($data['last_evaluated_at'])
                                        : null,
            triggeredAt:            isset($data['triggered_at']) && $data['triggered_at'] !== null
                                        ? new \DateTimeImmutable($data['triggered_at'])
                                        : null,
            resolvedAt:             isset($data['resolved_at']) && $data['resolved_at'] !== null
                                        ? new \DateTimeImmutable($data['resolved_at'])
                                        : null,
            createdAt:              new \DateTimeImmutable($data['created_at']),
            createdBy:              (string) $data['created_by'],
            conditions:             array_map(fn(array $c) => GuardrailCondition::fromArray($c), $conditions),
            ackRequired:            (bool) ($data['ack_required'] ?? false),
        );
    }
}
