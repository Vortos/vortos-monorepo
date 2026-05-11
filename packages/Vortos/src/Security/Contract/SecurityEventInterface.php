<?php

declare(strict_types=1);

namespace Vortos\Security\Contract;

interface SecurityEventInterface
{
    /** Machine-readable event name for metrics and log context. */
    public function eventName(): string;

    /** Returns contextual data for logging and security observers. */
    public function context(): array;
}
