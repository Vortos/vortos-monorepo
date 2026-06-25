<?php

declare(strict_types=1);

namespace Vortos\Alerts\Dedupe;

/**
 * Tracks `(fingerprint → firstSeen, lastSeen, count, state)` (§3.3). The default
 * (prod) implementation is {@see DbalAlertStateStore}, single-writer per fingerprint
 * via the same advisory-lock discipline Block 16 used; {@see InMemoryAlertStateStore}
 * is the unit/test default.
 */
interface AlertStateStoreInterface
{
    public function get(string $fingerprint): ?AlertState;

    public function save(AlertState $state): void;
}
