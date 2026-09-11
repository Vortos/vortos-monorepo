<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\Exposure\Ledger;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Vortos\FeatureFlags\Exposure\ExposureRecord;
use Vortos\FeatureFlags\Exposure\ExposureSource;
use Vortos\FeatureFlags\Exposure\Ledger\LedgerExposureObserver;
use Vortos\FeatureFlags\Exposure\Ledger\SubjectPseudonymiser;

/**
 * The buffer that keeps the ledger off the request's critical path — and the clamp that keeps
 * a public endpoint from choosing which partition it writes to.
 */
final class LedgerExposureObserverTest extends TestCase
{
    private const PEPPER = 'e6f1c0aa8b2d4f7391c5ae0b7d2f48366a9c1e5b0d8342f7ac916be4d05f2371';
    private const NOW    = '2026-09-11 12:00:00';

    public function test_it_buffers_an_exposure(): void
    {
        $observer = $this->observer();
        $observer->onExposure($this->record());

        $drained = $observer->drain();

        self::assertCount(1, $drained);
        self::assertSame('checkout', $drained[0]->flag);
        self::assertSame('true', $drained[0]->variant);
        self::assertSame('2026-09-11', $drained[0]->day);
    }

    public function test_it_stores_a_pseudonym_and_never_the_subject_id(): void
    {
        $observer = $this->observer();
        $observer->onExposure($this->record(subjectId: 'user-42'));

        $hash = $observer->drain()[0]->subjectHash;

        self::assertNotSame('user-42', $hash);
        self::assertSame((new SubjectPseudonymiser(self::PEPPER))->pseudonymise('user-42'), $hash);
    }

    public function test_it_is_inert_when_disabled(): void
    {
        // Default-off is the contract: enabling the ledger writes personal data, which an
        // operator opts into rather than inherits from an upgrade.
        $observer = $this->observer(enabled: false);
        $observer->onExposure($this->record());

        self::assertSame([], $observer->drain());
    }

    public function test_the_same_subject_and_result_collapses_within_a_request(): void
    {
        $observer = $this->observer();
        $observer->onExposure($this->record());
        $observer->onExposure($this->record());

        self::assertCount(1, $observer->drain());
    }

    public function test_a_different_variant_is_a_different_exposure(): void
    {
        $observer = $this->observer();
        $observer->onExposure($this->record(variant: 'true'));
        $observer->onExposure($this->record(variant: 'false'));

        self::assertCount(2, $observer->drain());
    }

    public function test_draining_empties_the_buffer(): void
    {
        $observer = $this->observer();
        $observer->onExposure($this->record());

        $observer->drain();

        // Drain rather than copy: a flusher failure must not replay these against the next
        // request, which would attribute them to the wrong day across midnight.
        self::assertSame([], $observer->drain());
    }

    public function test_reset_clears_the_buffer(): void
    {
        // The worker-state trap: this service outlives the request under FrankenPHP, so one
        // request's subjects must never be visible to the next.
        $observer = $this->observer();
        $observer->onExposure($this->record());

        $observer->reset();

        self::assertSame([], $observer->drain());
    }

    public function test_it_stops_buffering_at_the_ceiling(): void
    {
        $observer = $this->observer(maxPerRequest: 3);

        for ($i = 0; $i < 50; $i++) {
            $observer->onExposure($this->record(flag: 'flag-' . $i));
        }

        self::assertCount(3, $observer->drain());
    }

    public function test_it_honours_a_reported_timestamp(): void
    {
        $observer = $this->observer();
        // Yesterday, within the backdate allowance: an SDK that buffered offline is legitimate
        // and its exposure belongs to the day it was observed, not the day it arrived.
        $observer->onExposure($this->record(timestamp: strtotime('2026-09-10 23:30:00 UTC')));

        self::assertSame('2026-09-10', $observer->drain()[0]->day);
    }

    public function test_it_clamps_a_timestamp_from_the_far_future(): void
    {
        // Hostile input. Unclamped, one subject could mint unbounded rows across fabricated
        // days — defeating both the Redis dedupe and the primary key, because every row would
        // genuinely be new.
        $observer = $this->observer();
        $observer->onExposure($this->record(timestamp: strtotime('2031-01-01 00:00:00 UTC')));

        self::assertSame('2026-09-11', $observer->drain()[0]->day);
    }

    public function test_it_clamps_a_timestamp_from_the_distant_past(): void
    {
        $observer = $this->observer();
        $observer->onExposure($this->record(timestamp: strtotime('2001-01-01 00:00:00 UTC')));

        // Clamped to the backdate floor — 48 hours before the frozen now — rather than
        // dropped, because a user with a broken clock is normal and discarding them would
        // bias the experiment against badly-configured devices.
        self::assertSame('2026-09-09', $observer->drain()[0]->day);
    }

    public function test_it_never_throws_into_evaluation(): void
    {
        // onExposure runs inside isEnabled(). A throw here would turn a flag check into a 500.
        $observer = new LedgerExposureObserver(
            new SubjectPseudonymiser(self::PEPPER),
            new class implements ClockInterface {
                public function now(): DateTimeImmutable
                {
                    throw new \RuntimeException('clock is down');
                }
            },
            enabled: true,
        );

        $observer->onExposure($this->record());

        self::assertSame([], $observer->drain());
    }

    public function test_it_carries_the_group_association(): void
    {
        $observer = $this->observer();
        $observer->onExposure($this->record(groups: ['organization' => 'org-7']));

        self::assertSame('org-7', $observer->drain()[0]->groupKey);
    }

    public function test_a_missing_group_becomes_an_empty_string_not_null(): void
    {
        // '' rather than NULL because group_key is part of the rollup's primary key, and
        // Postgres does not consider two NULLs equal — a nullable component would stop
        // deduplicating exactly the rows it exists to deduplicate.
        $observer = $this->observer();
        $observer->onExposure($this->record(groups: []));

        self::assertSame('', $observer->drain()[0]->groupKey);
    }

    private function observer(
        bool $enabled = true,
        int $maxPerRequest = LedgerExposureObserver::DEFAULT_MAX_PER_REQUEST,
    ): LedgerExposureObserver {
        return new LedgerExposureObserver(
            new SubjectPseudonymiser(self::PEPPER),
            $this->clock(),
            enabled: $enabled,
            maxPerRequest: $maxPerRequest,
        );
    }

    private function clock(): ClockInterface
    {
        // Passed in rather than read from the enclosing class: an anonymous class nested in
        // another does NOT inherit its scope, so a private const reference here would be a
        // fatal error at first use rather than a compile-time one.
        return new class (self::NOW) implements ClockInterface {
            public function __construct(private readonly string $now) {}

            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable($this->now, new DateTimeZone('UTC'));
            }
        };
    }

    /** @param array<string,string> $groups */
    private function record(
        string $flag = 'checkout',
        ?string $variant = 'true',
        string $subjectId = 'user-42',
        ?int $timestamp = null,
        array $groups = [],
    ): ExposureRecord {
        return new ExposureRecord(
            flag:       $flag,
            variant:    $variant,
            contextKey: 'ctx-' . $subjectId,
            source:     ExposureSource::Server,
            timestamp:  $timestamp,
            groups:     $groups,
            subjectId:  $subjectId,
        );
    }
}
