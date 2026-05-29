<?php

declare(strict_types=1);

namespace Vortos\Paddle\Report;

final class EventLogPage
{
    /**
     * @param EventLogEntry[] $entries
     */
    public function __construct(
        public readonly array   $entries,
        public readonly ?string $nextCursor,
        public readonly bool    $hasMore,
    ) {}
}
