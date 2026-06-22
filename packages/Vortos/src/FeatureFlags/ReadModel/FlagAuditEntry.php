<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\ReadModel;

/**
 * One row of the flag audit log read model (Block 7).
 *
 * Projected from the domain event stream — the immutable, append-only history that the
 * History/diff/revert UI (Block 24) and webhooks (Block 18) read. Keyed by the event id
 * so re-delivery upserts the same row (idempotent).
 *
 * Unlike event payloads, read models may have methods.
 */
final class FlagAuditEntry
{
    /**
     * @param array<string,mixed> $data event-specific detail (diff or snapshot)
     */
    public function __construct(
        public readonly string $eventId,
        public readonly string $flagId,
        public readonly string $flagName,
        public readonly string $eventType,
        public readonly string $actorId,
        public readonly ?string $reason,
        public readonly string $occurredAt,
        public readonly array $data,
        public readonly string $environment = 'production',
    ) {}

    /** @return array<string,mixed> */
    public function toDocument(): array
    {
        return [
            '_id'         => $this->eventId,
            'flag_id'     => $this->flagId,
            'flag_name'   => $this->flagName,
            'environment' => $this->environment,
            'event_type'  => $this->eventType,
            'actor_id'    => $this->actorId,
            'reason'      => $this->reason,
            'occurred_at' => $this->occurredAt,
            'data'        => $this->data,
        ];
    }

    /** @param array<string,mixed> $doc */
    public static function fromDocument(array $doc): self
    {
        return new self(
            eventId:     (string) $doc['_id'],
            flagId:      (string) ($doc['flag_id'] ?? ''),
            flagName:    (string) ($doc['flag_name'] ?? ''),
            eventType:   (string) ($doc['event_type'] ?? ''),
            actorId:     (string) ($doc['actor_id'] ?? ''),
            reason:      $doc['reason'] ?? null,
            occurredAt:  (string) ($doc['occurred_at'] ?? ''),
            data:        (array) ($doc['data'] ?? []),
            environment: (string) ($doc['environment'] ?? 'production'),
        );
    }
}
