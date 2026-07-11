<?php

declare(strict_types=1);

namespace Vortos\Audit\Query;

use Vortos\Audit\Storage\StoredAuditEvent;

/**
 * One keyset page: the records plus the cursor to fetch the next page (null when the
 * last page has been reached).
 */
final readonly class AuditPage
{
    /**
     * @param list<StoredAuditEvent> $records
     */
    public function __construct(
        public array        $records,
        public ?AuditCursor $nextCursor,
    ) {}

    public function isLastPage(): bool
    {
        return $this->nextCursor === null;
    }
}
