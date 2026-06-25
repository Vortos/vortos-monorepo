<?php

declare(strict_types=1);

namespace Vortos\Alerts\Notifier;

/** Bounded in-process dedupe — recently-seen keys only, capped so a long-lived process never grows unbounded. */
final class InMemoryDeliveryDedupeStore implements DeliveryDedupeStoreInterface
{
    /** @var array<string, true> */
    private array $seen = [];

    public function __construct(private readonly int $maxEntries = 10_000)
    {
    }

    public function seen(string $idempotencyKey): bool
    {
        return isset($this->seen[$idempotencyKey]);
    }

    public function remember(string $idempotencyKey): void
    {
        if (count($this->seen) >= $this->maxEntries) {
            array_shift($this->seen);
        }

        $this->seen[$idempotencyKey] = true;
    }
}
