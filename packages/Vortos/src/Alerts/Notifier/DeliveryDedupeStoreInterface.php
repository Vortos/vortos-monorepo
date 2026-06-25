<?php

declare(strict_types=1);

namespace Vortos\Alerts\Notifier;

/** Tracks delivered idempotency keys so a retry never double-pages. */
interface DeliveryDedupeStoreInterface
{
    public function seen(string $idempotencyKey): bool;

    public function remember(string $idempotencyKey): void;
}
