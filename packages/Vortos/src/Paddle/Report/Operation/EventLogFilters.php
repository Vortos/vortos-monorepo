<?php

declare(strict_types=1);

namespace Vortos\Paddle\Report\Operation;

final class EventLogFilters
{
    public function __construct(
        public readonly ?string $eventType = null,
        public readonly ?string $after = null,
    ) {}
}
