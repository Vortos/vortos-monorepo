<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Exposure\Ledger;

use DateTimeImmutable;
use Vortos\FeatureFlags\Exposure\ExposureSource;

/**
 * One exposure as it is written to the **first-party ledger** — the complete, unsampled,
 * consent-independent record an experiment readout is computed from.
 *
 * ## Why this exists alongside the analytics bridge
 *
 * {@see \Vortos\Analytics\Bridge\AnalyticsExposureObserver} forwards exposures to a
 * third-party analytics backend. That path is, correctly, **consent-gated and sampled**:
 * it is a marketing-grade stream and a subject who has not consented must not appear in
 * it. The consequence is that it is a *biased sample* — and a biased sample cannot answer
 * "did this flag move the metric", because the subjects it drops are not dropped at
 * random (consent correlates with tenant size, jurisdiction and privacy posture, all of
 * which correlate with behaviour).
 *
 * This ledger is the other stream: first-party, complete, and pseudonymous by
 * construction. It never leaves the deployment, so it is measurement rather than
 * disclosure — which is what lets it run under a lawful basis that does not require
 * per-subject consent. Both streams observe the *same* exposures; they differ only in
 * who is allowed to see them and how many survive.
 *
 * ## The grain is one row per (day, subject, flag, variant)
 *
 * Not one row per evaluation. `isEnabled()` is called on the request path, often many
 * times per request and many requests per day, and a per-evaluation ledger would be the
 * highest-volume table in the system while answering no question a per-day ledger cannot.
 * Experiment statistics count *subjects*, not calls. Collapsing to a day grain is
 * therefore free of information and cheap by orders of magnitude — see
 * {@see DailyExposureDeduper}, which enforces the grain before a row is ever built.
 *
 * ## `subjectHash`, never a subject id
 *
 * The ledger stores an HMAC of the analytics identity, not the identity — see
 * {@see SubjectPseudonymiser}. It is stable (so a subject stays in one arm across days),
 * joinable within the deployment, and not reversible into a user id by anyone who
 * obtains a database dump without also obtaining the pepper.
 */
final readonly class LedgerExposure
{
    public function __construct(
        public string $subjectHash,
        public string $flag,
        public string $variant,
        public ExposureSource $source,
        public string $groupKey,
        public string $day,
        public DateTimeImmutable $firstSeenAt,
    ) {}

    /**
     * The day-grain identity of this exposure. Two exposures sharing it are the same
     * subject seeing the same result on the same day, which is one fact, not two.
     *
     * Mirrors the primary key of the ledger table exactly, so the Redis pre-filter and
     * the database's own `ON CONFLICT` agree on what a duplicate is. If they disagreed,
     * a Redis outage would produce rows the deduper thinks are new and the table
     * silently rejects, and the accepted count would stop meaning anything.
     */
    public function dedupeKey(): string
    {
        return $this->day . '|' . $this->subjectHash . '|' . $this->flag . '|' . $this->variant;
    }
}
