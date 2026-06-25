<?php

declare(strict_types=1);

namespace Vortos\Observability\Buffer;

/**
 * One decoded record drained from a {@see BoundedSpool}: the original payload plus
 * the epoch-millis timestamp it was enqueued at (used for age-based metrics).
 */
final readonly class SpoolRecord
{
    public function __construct(
        public string $payload,
        public int $enqueuedAtMs,
    ) {}
}
