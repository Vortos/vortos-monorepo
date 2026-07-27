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

    /**
     * Open alerts whose condition has not been observed since $threshold.
     *
     * Needed because an alert that stops firing is not the same as an alert that was dealt with,
     * and until now nothing distinguished them: `AlertStateStatus::Resolved` existed as a value
     * that no code ever assigned, so every alert ever raised stayed `open` forever. The practical
     * cost is not noise — a condition that stops firing stops being dispatched — it is that "how
     * many things are wrong right now?" becomes unanswerable, and a dashboard nobody can trust is
     * the same as no dashboard.
     *
     * @return list<AlertState>
     */
    public function openSince(\DateTimeImmutable $threshold): array;
}
