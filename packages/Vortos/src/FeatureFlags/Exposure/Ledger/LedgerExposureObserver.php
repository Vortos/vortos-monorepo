<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Exposure\Ledger;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;
use Vortos\FeatureFlags\Exposure\ExposureObserverInterface;
use Vortos\FeatureFlags\Exposure\ExposureRecord;

/**
 * Observes every accepted exposure into an in-memory buffer, and does nothing else.
 *
 * ## Why this does no I/O
 *
 * `onExposure()` is called synchronously from inside `isEnabled()`. Anything it touches —
 * a socket, a database, a cache — lands directly in the latency of a request that is still
 * being served, on a path that application code treats as free and therefore calls without
 * thinking. A single blocking millisecond here is multiplied by every flag check in the
 * codebase.
 *
 * So this class only appends to an array. The Redis round trip and the insert both happen in
 * {@see ExposureLedgerFlusher}, after the response has been sent. The buffer is the seam
 * between "must be instant" and "may touch the network".
 *
 * ## Not consent-gated, and not sampled
 *
 * Deliberately, and this is the entire difference between this observer and
 * {@see \Vortos\Analytics\Bridge\AnalyticsExposureObserver}. That one forwards to a third
 * party, so it must honour consent and may sample to protect a quota. This one writes to
 * first-party storage that never leaves the deployment, and a readout computed from a
 * consent-filtered or sampled exposure log is a readout over a self-selected population —
 * which is not a smaller answer but a wrong one, because consent and sampling-by-identity
 * both correlate with the behaviour being measured.
 *
 * The subject is pseudonymised here rather than at write time so that no raw identity is
 * ever held in the buffer, even transiently across a request.
 *
 * ## Worker safety
 *
 * The buffer is per-request state on a service that, under a long-lived worker, outlives the
 * request by design. It is therefore cleared through {@see ResetInterface} — the same
 * contract the exposure decorator uses — and drained by the flusher on the way out. Both
 * paths empty it, so a request that terminates abnormally cannot leak its subjects into the
 * next one served by the same worker.
 */
final class LedgerExposureObserver implements ExposureObserverInterface, ResetInterface
{
    /**
     * Hard ceiling on buffered exposures per request. Reached only by pathological code (a
     * flag evaluated with a different context inside a large loop); the point is that a bug
     * upstream becomes a bounded memory cost rather than an unbounded one.
     */
    public const DEFAULT_MAX_PER_REQUEST = 500;

    /**
     * How far back a reported exposure may be dated. Wide enough for an SDK that buffered
     * while offline and flushed on reconnect; narrow enough that the ledger only ever spans
     * a couple of retention-reachable days.
     */
    public const MAX_BACKDATE_SECONDS = 172800; // 48 hours

    /**
     * How far ahead of server time a reported exposure may be dated. Covers client clock
     * skew only — an exposure observed *after* it was reported is not a thing that happens.
     */
    public const MAX_FUTURE_SKEW_SECONDS = 300; // 5 minutes

    /** @var array<string,LedgerExposure> keyed by dedupe key, so a repeat is free */
    private array $buffer = [];

    public function __construct(
        private readonly SubjectPseudonymiser $pseudonymiser,
        private readonly ClockInterface $clock,
        private readonly bool $enabled = false,
        private readonly string $groupType = 'organization',
        private readonly int $maxPerRequest = self::DEFAULT_MAX_PER_REQUEST,
    ) {}

    public function onExposure(ExposureRecord $record): void
    {
        if (!$this->enabled || count($this->buffer) >= $this->maxPerRequest) {
            return;
        }

        try {
            $observedAt = $this->observedAt($record);

            $exposure = new LedgerExposure(
                subjectHash: $this->pseudonymiser->pseudonymise($record->analyticsId()),
                flag:        $record->flag,
                // Normalised to a string here so the primary key never has to reason about
                // NULL. A boolean flag always reports 'true'/'false'; '' means "evaluated,
                // but the registry could not name the result", which is still a real
                // exposure and must not be silently dropped.
                variant:     $record->variant ?? '',
                source:      $record->source,
                groupKey:    $record->groups[$this->groupType] ?? '',
                day:         $observedAt->format('Y-m-d'),
                firstSeenAt: $observedAt,
            );

            $this->buffer[$exposure->dedupeKey()] = $exposure;
        } catch (Throwable) {
            // Intentionally swallowed: an observer can never break evaluation.
        }
    }

    /**
     * Hand over everything buffered and forget it.
     *
     * Drains rather than copies so that a flusher failure cannot cause the same exposures to
     * be retried against the next request on this worker, which would attribute them to the
     * wrong day once the clock crosses midnight.
     *
     * @return list<LedgerExposure>
     */
    public function drain(): array
    {
        $drained = array_values($this->buffer);
        $this->buffer = [];

        return $drained;
    }

    public function reset(): void
    {
        $this->buffer = [];
    }

    /**
     * When the exposure was observed, in UTC, clamped to a window around server time.
     *
     * An SDK reports its own clock and we honour it, because the alternative — stamping
     * ingest time — silently moves a browser's exposure into the wrong day near midnight and
     * corrupts exactly the boundary a daily grain is built on. A record with no timestamp
     * falls back to server time, which is the best available answer.
     *
     * ## Why it is clamped: this value is hostile input
     *
     * SDK exposures arrive through a public, unauthenticated endpoint, and the timestamp
     * travels with them. It also *selects the partition a row is written to*, because `day`
     * is derived from it and `day` leads the primary key. An unclamped timestamp is therefore
     * an unbounded-row-growth primitive: one subject replaying one exposure across a million
     * distinct fabricated days defeats the daily dedupe completely — every row looks new to
     * both Redis and the primary key — and writes a million rows that retention will not
     * reclaim for thirty days, or never, if the days are in the future.
     *
     * Clamping to {@see self::MAX_BACKDATE_SECONDS} in the past and {@see
     * self::MAX_FUTURE_SKEW_SECONDS} ahead bounds that to a handful of partitions. The
     * backdate allowance is generous enough for an SDK that buffered exposures offline; the
     * forward allowance covers ordinary client clock skew and nothing more, since a genuine
     * exposure cannot be observed after the moment it is reported.
     *
     * Clamping rather than rejecting is deliberate: a user with a badly-set clock is a
     * normal occurrence, and dropping their exposures would quietly bias the experiment
     * against whoever has the worst-configured devices.
     */
    private function observedAt(ExposureRecord $record): DateTimeImmutable
    {
        $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));

        if ($record->timestamp === null) {
            return $now;
        }

        $nowTs    = $now->getTimestamp();
        $clamped  = min(
            $nowTs + self::MAX_FUTURE_SKEW_SECONDS,
            max($nowTs - self::MAX_BACKDATE_SECONDS, $record->timestamp),
        );

        return (new DateTimeImmutable('@' . $clamped))->setTimezone(new DateTimeZone('UTC'));
    }
}
