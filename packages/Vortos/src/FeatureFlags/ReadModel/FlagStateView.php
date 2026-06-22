<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\ReadModel;

/**
 * The current projected state of a flag in one environment (Block 7/10 CQRS read model).
 *
 * Keyed by `(environment, flag_name)` — the same flag name may appear multiple times,
 * once per environment it has been configured in. The document `_id` is the compound
 * key `"{env}:{name}"`. Updated by {@see \Vortos\FeatureFlags\Projection\FlagReadModelProjector}
 * as events arrive; replaying the event stream reproduces it exactly (idempotent upserts).
 */
final class FlagStateView
{
    /**
     * @param array<string,int>|null $variants
     */
    public function __construct(
        public readonly string $flagName,
        public readonly string $flagId,
        public readonly bool $enabled,
        public readonly bool $archived,
        public readonly string $valueType,
        public readonly string $kind,
        public readonly int $ruleCount,
        public readonly ?array $variants,
        public readonly bool $scheduled,
        public readonly string $lastEventType,
        public readonly string $lastActorId,
        public readonly string $updatedAt,
        public readonly string $environment = 'production',
    ) {}

    /**
     * Compound document key: `"{env}:{flagName}"`.
     * Used as the upsert key in MongoDB (`_id`) and as the DBAL composite unique
     * `(environment, flag_name)` in the relational read model.
     */
    public function compoundKey(): string
    {
        return $this->environment . ':' . $this->flagName;
    }

    /** @return array<string,mixed> */
    public function toDocument(): array
    {
        return [
            '_id'             => $this->compoundKey(),
            'flag_name'       => $this->flagName,
            'flag_id'         => $this->flagId,
            'environment'     => $this->environment,
            'enabled'         => $this->enabled,
            'archived'        => $this->archived,
            'value_type'      => $this->valueType,
            'kind'            => $this->kind,
            'rule_count'      => $this->ruleCount,
            'variants'        => $this->variants,
            'scheduled'       => $this->scheduled,
            'last_event_type' => $this->lastEventType,
            'last_actor_id'   => $this->lastActorId,
            'updated_at'      => $this->updatedAt,
        ];
    }

    /** @param array<string,mixed> $doc */
    public static function fromDocument(array $doc): self
    {
        return new self(
            flagName:      (string) ($doc['flag_name'] ?? $doc['_id'] ?? ''),
            flagId:        (string) ($doc['flag_id'] ?? ''),
            enabled:       (bool) ($doc['enabled'] ?? false),
            archived:      (bool) ($doc['archived'] ?? false),
            valueType:     (string) ($doc['value_type'] ?? 'bool'),
            kind:          (string) ($doc['kind'] ?? 'release'),
            ruleCount:     (int) ($doc['rule_count'] ?? 0),
            variants:      $doc['variants'] ?? null,
            scheduled:     (bool) ($doc['scheduled'] ?? false),
            lastEventType: (string) ($doc['last_event_type'] ?? ''),
            lastActorId:   (string) ($doc['last_actor_id'] ?? ''),
            updatedAt:     (string) ($doc['updated_at'] ?? ''),
            environment:   (string) ($doc['environment'] ?? 'production'),
        );
    }
}
