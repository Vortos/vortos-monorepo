<?php

declare(strict_types=1);

namespace Vortos\Audit\Storage\Dbal;

use Vortos\Audit\Event\AuditEvent;
use Vortos\Audit\Storage\StoredAuditEvent;

/**
 * Maps an audit_events row to a {@see StoredAuditEvent}. Shared by the write store and
 * the query reader so hydration lives in exactly one place.
 */
final class StoredAuditEventRowMapper
{
    /**
     * @param array<string, mixed> $row
     */
    public static function toStored(array $row): StoredAuditEvent
    {
        $event = AuditEvent::fromArray([
            'id'          => $row['id'],
            'scope'       => $row['scope'],
            'tenant_id'   => $row['tenant_id'],
            'actor'       => json_decode((string) $row['actor'], true, 512, JSON_THROW_ON_ERROR),
            'action'      => $row['action'],
            'target'      => $row['target'] !== null && $row['target'] !== ''
                ? json_decode((string) $row['target'], true, 512, JSON_THROW_ON_ERROR)
                : null,
            'sensitivity' => $row['sensitivity'],
            'outcome'     => $row['outcome'],
            'source'      => json_decode((string) $row['source'], true, 512, JSON_THROW_ON_ERROR),
            'context'     => json_decode((string) ($row['context'] ?? '[]'), true, 512, JSON_THROW_ON_ERROR),
            'occurred_at' => $row['occurred_at'],
        ]);

        return new StoredAuditEvent(
            event:       $event,
            chainKey:    (string) $row['chain_key'],
            sequence:    (int) $row['sequence'],
            prevHash:    (string) $row['prev_hash'],
            contentHash: (string) $row['content_hash'],
            signature:   (string) $row['signature'],
        );
    }
}
