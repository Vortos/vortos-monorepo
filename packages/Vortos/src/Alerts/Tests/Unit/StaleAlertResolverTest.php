<?php

declare(strict_types=1);

namespace Vortos\Alerts\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Vortos\Alerts\Dedupe\AlertState;
use Vortos\Alerts\Dedupe\AlertStateStatus;
use Vortos\Alerts\Dedupe\AlertStateStoreInterface;
use Vortos\Alerts\Dedupe\InMemoryAlertStateStore;
use Vortos\Alerts\Runtime\StaleAlertResolver;

/**
 * `AlertStateStatus::Resolved` existed as a value nothing ever assigned, so every alert ever raised
 * stayed open for the life of the system. Not a noise problem — a condition that stops firing stops
 * being dispatched — but a trust one: the open-alert count only ever grew, so it answered nothing.
 */
final class StaleAlertResolverTest extends TestCase
{
    private const NOW = '2026-07-27T12:00:00+00:00';

    public function test_it_closes_an_alert_whose_condition_stopped_being_observed(): void
    {
        $store = new InMemoryAlertStateStore();
        $store->save($this->open('gone', lastSeen: '2026-07-27T10:00:00+00:00')); // 2h silent

        $closed = (new StaleAlertResolver($store, silenceSeconds: 3_600))
            ->resolveStale(new DateTimeImmutable(self::NOW));

        self::assertSame(1, $closed);
        self::assertSame(AlertStateStatus::Resolved, $store->get('gone')?->status);
    }

    public function test_it_leaves_an_alert_that_is_still_firing_open(): void
    {
        // Anything still firing refreshed its lastSeenAt on this very tick. Closing it would drop a
        // live problem off the board and re-announce it moments later — the pager storm the dedupe
        // layer exists to prevent.
        $store = new InMemoryAlertStateStore();
        $store->save($this->open('live', lastSeen: '2026-07-27T11:59:30+00:00')); // 30s ago

        $closed = (new StaleAlertResolver($store, silenceSeconds: 3_600))
            ->resolveStale(new DateTimeImmutable(self::NOW));

        self::assertSame(0, $closed);
        self::assertSame(AlertStateStatus::Open, $store->get('live')?->status);
    }

    public function test_it_does_not_flap_on_a_condition_that_misses_a_single_tick(): void
    {
        // A condition oscillating around its threshold can miss one evaluation. Several cycles of
        // silence is the evidence required, not one.
        $store = new InMemoryAlertStateStore();
        $store->save($this->open('flappy', lastSeen: '2026-07-27T11:55:00+00:00')); // 5m ago

        $closed = (new StaleAlertResolver($store, silenceSeconds: 3_600))
            ->resolveStale(new DateTimeImmutable(self::NOW));

        self::assertSame(0, $closed);
    }

    public function test_a_store_failure_never_breaks_the_alert_tick(): void
    {
        // This runs alongside source evaluation. Housekeeping must never be able to stop alerting.
        $store = new class implements AlertStateStoreInterface {
            public function get(string $fingerprint): ?AlertState { return null; }

            public function save(AlertState $state): void {}

            public function openSince(DateTimeImmutable $threshold): array
            {
                throw new \RuntimeException('state store unavailable');
            }
        };

        $closed = (new StaleAlertResolver($store))->resolveStale(new DateTimeImmutable(self::NOW));

        self::assertSame(0, $closed);
    }

    private function open(string $fingerprint, string $lastSeen): AlertState
    {
        return new AlertState(
            fingerprint: $fingerprint,
            status: AlertStateStatus::Open,
            firstSeenAt: new DateTimeImmutable('2026-07-27T09:00:00+00:00'),
            lastSeenAt: new DateTimeImmutable($lastSeen),
            occurrenceCount: 42,
        );
    }
}
