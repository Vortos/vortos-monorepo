<?php

declare(strict_types=1);

namespace Vortos\Alerts\Integration\Audit;

use InvalidArgumentException;

/**
 * One append-only, hash-chained, HMAC-signed row recording "who was paged / who
 * acked, when" (§3.7, §4.5) — reuses the Block 16 {@see \Vortos\Observability\Audit\AuditHashChain}
 * signing discipline (the one audit mechanism, §11.3), with its own entry shape
 * since alert notifications/acks don't carry deploy-specific fields.
 */
final readonly class AlertAuditEntry
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public string $entryId,
        public int $sequence,
        public string $env,
        public string $eventType,
        public string $fingerprint,
        public string $actorId,
        public string $occurredAt,
        public array $data,
        public string $prevHash,
        public string $contentHash,
        public string $signature,
    ) {
        if ($sequence < 0) {
            throw new InvalidArgumentException('AlertAuditEntry sequence must be >= 0.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'entry_id' => $this->entryId,
            'sequence' => $this->sequence,
            'env' => $this->env,
            'event_type' => $this->eventType,
            'fingerprint' => $this->fingerprint,
            'actor_id' => $this->actorId,
            'occurred_at' => $this->occurredAt,
            'data' => $this->data,
            'prev_hash' => $this->prevHash,
            'content_hash' => $this->contentHash,
            'signature' => $this->signature,
        ];
    }

    /**
     * The fields covered by the content hash — everything except the chain/signature fields.
     *
     * @return array<string, mixed>
     */
    public function hashableFields(): array
    {
        $fields = $this->toArray();
        unset($fields['prev_hash'], $fields['content_hash'], $fields['signature']);

        return $fields;
    }
}
