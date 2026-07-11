<?php

declare(strict_types=1);

namespace Vortos\Audit\Retention;

use Vortos\Audit\Storage\StoredAuditEvent;

/**
 * Serialises stored records to NDJSON (one JSON object per line) for the cold archive —
 * a streaming-friendly, append-friendly format that keeps every chain field so an
 * archived segment can be re-verified offline.
 */
final class StoredAuditEventSerializer
{
    public function toNdjson(StoredAuditEvent ...$records): string
    {
        $lines = array_map(
            static fn (StoredAuditEvent $r): string => json_encode([
                'event'        => $r->event->toArray(),
                'chain_key'    => $r->chainKey,
                'sequence'     => $r->sequence,
                'prev_hash'    => $r->prevHash,
                'content_hash' => $r->contentHash,
                'signature'    => $r->signature,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $records,
        );

        return implode("\n", $lines);
    }
}
