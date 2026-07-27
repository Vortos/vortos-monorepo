<?php

declare(strict_types=1);

namespace Vortos\Alerts\Tests\Integration\Dedupe;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Vortos\Alerts\Dedupe\AlertState;
use Vortos\Alerts\Dedupe\AlertStateStatus;
use Vortos\Alerts\Dedupe\DbalAlertStateStore;

final class DbalAlertStateStoreTest extends TestCase
{
    private Connection $connection;
    private DbalAlertStateStore $store;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement(
            'CREATE TABLE alerts_state (
                fingerprint VARCHAR(64) PRIMARY KEY,
                status VARCHAR(16) NOT NULL,
                first_seen_at VARCHAR(32) NOT NULL,
                last_seen_at VARCHAR(32) NOT NULL,
                occurrence_count INTEGER NOT NULL,
                flap_transitions INTEGER NOT NULL,
                flap_window_start_at VARCHAR(32),
                flap_escalated_at VARCHAR(32),
                last_notified_at VARCHAR(32),
                reminder_count INTEGER NOT NULL DEFAULT 0
            )',
        );
        $this->store = new DbalAlertStateStore($this->connection, 'alerts_state');
    }

    public function test_reminder_backoff_state_survives_a_restart(): void
    {
        // If lastNotifiedAt and reminderCount do not persist, every process restart resets the
        // backoff to full volume — and on a blue/green deploy that is several times a day, which
        // puts the alert spam straight back. The whole point of widening reminders is that the
        // widening is remembered.
        $now = new DateTimeImmutable('2026-07-27T03:00:00+00:00');

        $state = new AlertState(
            fingerprint: 'fp-backoff',
            status: AlertStateStatus::Open,
            firstSeenAt: $now,
            lastSeenAt: $now,
            occurrenceCount: 120,
            lastNotifiedAt: $now,
            reminderCount: 4,
        );

        $this->store->save($state);

        // A fresh store instance stands in for a restarted process reading the same table.
        $reloaded = (new DbalAlertStateStore($this->connection, 'alerts_state'))->get('fp-backoff');

        self::assertNotNull($reloaded);
        self::assertSame(4, $reloaded->reminderCount, 'reminder count must survive a restart');
        self::assertNotNull($reloaded->lastNotifiedAt);
        self::assertSame(
            $now->format(DateTimeImmutable::ATOM),
            $reloaded->lastNotifiedAt->format(DateTimeImmutable::ATOM),
        );
    }

    public function test_round_trips_state(): void
    {
        $now = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $state = AlertState::firstSeen('fp-1', $now);

        $this->store->save($state);
        $loaded = $this->store->get('fp-1');

        self::assertNotNull($loaded);
        self::assertSame('fp-1', $loaded->fingerprint);
        self::assertSame(AlertStateStatus::Open, $loaded->status);
        self::assertSame(1, $loaded->occurrenceCount);
    }

    public function test_unknown_fingerprint_returns_null(): void
    {
        self::assertNull($this->store->get('does-not-exist'));
    }

    public function test_update_overwrites_existing_row(): void
    {
        $now = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $state = AlertState::firstSeen('fp-1', $now);
        $this->store->save($state);

        $updated = $state->withOccurrence($now->modify('+10 seconds'));
        $this->store->save($updated);

        $loaded = $this->store->get('fp-1');
        self::assertSame(2, $loaded->occurrenceCount);
    }

    public function test_replay_equals_live_state(): void
    {
        $now = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $state = AlertState::firstSeen('fp-1', $now);
        for ($i = 0; $i < 5; $i++) {
            $state = $state->withOccurrence($now->modify("+{$i} seconds"));
            $this->store->save($state);
        }

        $loaded = $this->store->get('fp-1');
        self::assertSame($state->occurrenceCount, $loaded->occurrenceCount);
        self::assertSame($state->lastSeenAt->format(DATE_ATOM), $loaded->lastSeenAt->format(DATE_ATOM));
    }
}
