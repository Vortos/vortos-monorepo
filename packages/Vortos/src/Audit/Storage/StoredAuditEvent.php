<?php

declare(strict_types=1);

namespace Vortos\Audit\Storage;

use Vortos\Audit\Event\AuditEvent;

/**
 * An {@see AuditEvent} committed to a chain: the domain event plus the storage-assigned
 * tamper-evidence fields. The domain event stays free of chain concerns; those are added
 * here at write time.
 */
final readonly class StoredAuditEvent
{
    public function __construct(
        public AuditEvent $event,
        public string     $chainKey,
        public int        $sequence,
        public string     $prevHash,
        public string     $contentHash,
        public string     $signature,
    ) {}
}
