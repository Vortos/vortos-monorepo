<?php

declare(strict_types=1);

namespace Vortos\Observability\Marker;

/**
 * Bounded, drop-oldest, per-process idempotency-key seen-set. A retried deploy
 * within the same process lifetime never double-annotates; across process
 * restarts the outbox's own enqueue dedupe window is shorter than a deploy's
 * lifecycle, which is an acceptable best-effort trade-off for a non-blocking,
 * best-effort marker pipeline.
 */
final class InMemoryDedupeStore implements DedupeStore
{
    /** @var array<string, true> */
    private array $seen = [];

    public function __construct(
        private readonly int $maxEntries = 10_000,
    ) {
    }

    public function seen(string $key): bool
    {
        return isset($this->seen[$key]);
    }

    public function remember(string $key): void
    {
        $this->seen[$key] = true;

        if (count($this->seen) > $this->maxEntries) {
            array_shift($this->seen);
        }
    }
}
