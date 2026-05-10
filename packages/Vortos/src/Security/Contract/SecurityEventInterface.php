<?php

declare(strict_types=1);

namespace Vortos\Security\Contract;

interface SecurityEventInterface
{
    /** Machine-readable event name for metrics and log context. */
    public function eventName(): string;

    /** Returns a map of contextual data for logging and metrics labels. */
    public function context(): array;
}
