<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Exposure\Ledger;

/**
 * Append-only sink for first-party exposures.
 *
 * Separate from {@see \Vortos\FeatureFlags\Exposure\ExposureObserverInterface} on purpose:
 * that seam is notified synchronously during evaluation and must never do I/O, while this
 * one is driven after the response has been sent and is allowed to touch the database.
 * Keeping them apart is what stops a slow write from ever appearing in request latency.
 *
 * Implementations MUST be idempotent on {@see LedgerExposure::dedupeKey()} — a retry, a
 * Redis outage, or two workers racing the same subject must converge on one row.
 */
interface ExposureLedgerInterface
{
    /**
     * @param  list<LedgerExposure> $exposures
     * @return int                  rows actually appended (duplicates are not counted)
     */
    public function append(array $exposures): int;
}
