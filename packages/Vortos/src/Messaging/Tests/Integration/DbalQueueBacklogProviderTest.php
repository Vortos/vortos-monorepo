<?php

declare(strict_types=1);

namespace Vortos\Messaging\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Vortos\Messaging\Integration\Alerts\DbalQueueBacklogProvider;

/**
 * The provider feeds every `queue_lag` alert rule, so a reading it does not emit is a failure
 * nobody can alert on. These tests pin the three surfaces it must report.
 */
final class DbalQueueBacklogProviderTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        $this->connection->executeStatement(
            'CREATE TABLE outbox (
                id INTEGER PRIMARY KEY,
                transport_name VARCHAR(64) NOT NULL,
                status VARCHAR(20) NOT NULL,
                created_at DATETIME NOT NULL
            )'
        );
        $this->connection->executeStatement(
            'CREATE TABLE failed_messages (
                id INTEGER PRIMARY KEY,
                transport_name VARCHAR(64) NOT NULL,
                status VARCHAR(20) NOT NULL,
                failed_at DATETIME NOT NULL
            )'
        );
    }

    private function provider(): DbalQueueBacklogProvider
    {
        return new DbalQueueBacklogProvider($this->connection, 'outbox', 'failed_messages');
    }

    private function insertOutbox(string $status, string $createdAt, string $transport = 'kafka'): void
    {
        $this->connection->insert('outbox', [
            'transport_name' => $transport,
            'status' => $status,
            'created_at' => $createdAt,
        ]);
    }

    /** @return array<string, \Vortos\Alerts\Integration\Messaging\QueueBacklog> */
    private function byQueue(): array
    {
        $out = [];
        foreach ($this->provider()->backlogs() as $b) {
            $out[$b->queue] = $b;
        }

        return $out;
    }

    public function test_rows_that_exhausted_their_retries_are_reported(): void
    {
        // THE BLIND SPOT. A `failed` row is not `pending`, so the outbox gauge excludes it, and it
        // never reached the dead-letter table — so before this reading existed, nothing measured it
        // at all. Production sat on 11 such rows for two weeks (three of them PaymentCompleted)
        // with every dashboard green, because a terminal state stops moving and stops being seen.
        $this->insertOutbox('failed', '2026-07-13 07:41:59');
        $this->insertOutbox('failed', '2026-07-19 15:28:24');
        $this->insertOutbox('published', '2026-07-20 10:00:00');

        $backlogs = $this->byQueue();

        self::assertArrayHasKey('outbox-failed:kafka', $backlogs);
        self::assertSame(2, $backlogs['outbox-failed:kafka']->depth);
    }

    public function test_published_rows_are_never_counted_as_backlog(): void
    {
        // A delivered row is history. Counting it would make every gauge climb forever and render
        // every threshold meaningless within days.
        $this->insertOutbox('published', '2026-07-20 10:00:00');
        $this->insertOutbox('published', '2026-07-20 10:00:01');

        $backlogs = $this->byQueue();

        self::assertArrayNotHasKey('outbox:kafka', $backlogs);
        self::assertArrayNotHasKey('outbox-failed:kafka', $backlogs);
    }

    public function test_pending_rows_report_depth_and_age_separately(): void
    {
        // Age is the reading that catches a stalled relay: a queue holding a steady few messages
        // looks healthy on depth alone until you notice they are the SAME messages, hours old.
        $this->insertOutbox('pending', (new \DateTimeImmutable('-2 hours'))->format('Y-m-d H:i:s'));
        $this->insertOutbox('pending', (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s'));

        $backlogs = $this->byQueue();

        self::assertArrayHasKey('outbox:kafka', $backlogs);
        self::assertSame(2, $backlogs['outbox:kafka']->depth);
        self::assertNotNull($backlogs['outbox:kafka']->oldestAgeSeconds);
        self::assertGreaterThan(3000, $backlogs['outbox:kafka']->oldestAgeSeconds);
    }

    public function test_dead_letters_are_reported_under_their_own_queue_prefix(): void
    {
        // Prefixes keep a rule scoped to one surface: "anything in the DLQ" must not accidentally
        // also match a transport of the same name on the outbox.
        $this->insertDeadLetter('failed', '-10 minutes');

        $backlogs = $this->byQueue();

        self::assertArrayHasKey('dlq:kafka', $backlogs);
        self::assertSame(1, $backlogs['dlq:kafka']->depth);
    }

    /**
     * A replayed dead letter is history, not backlog.
     *
     * Replaying sets status='replayed' but keeps the row for audit, and this reading counted every
     * row in the table — so the gauge only ever climbed and a `dlq-not-empty` rule with the obvious
     * threshold of zero could never resolve again once anything had ever been dead-lettered.
     *
     * Production ran that way: 21 messages dead-lettered in July 2026 were replayed successfully
     * and the alert went on paging for weeks against 21 rows of history, while `vortos:dlq:list`
     * showed an empty queue the whole time — because the repository filters status='failed'. The
     * tool operators check and the alarm that paged them disagreed, and the alarm was wrong.
     */
    public function test_replayed_dead_letters_are_not_backlog(): void
    {
        $this->insertDeadLetter('replayed', '-3 days');
        $this->insertDeadLetter('replayed', '-2 days');

        self::assertArrayNotHasKey('dlq:kafka', $this->byQueue());
    }

    /** A drained queue must read as drained even while its audit history remains. */
    public function test_only_the_still_failed_rows_count_toward_depth(): void
    {
        $this->insertDeadLetter('replayed', '-3 days');
        $this->insertDeadLetter('failed', '-1 hour');
        $this->insertDeadLetter('replayed', '-2 days');

        self::assertSame(1, $this->byQueue()['dlq:kafka']->depth);
    }

    private function insertDeadLetter(string $status, string $failedAt, string $transport = 'kafka'): void
    {
        $this->connection->insert('failed_messages', [
            'transport_name' => $transport,
            'status' => $status,
            'failed_at' => (new \DateTimeImmutable($failedAt))->format('Y-m-d H:i:s'),
        ]);
    }

    public function test_a_database_failure_yields_no_readings_rather_than_false_zeros(): void
    {
        // "Nothing evaluated this round" must never be reported as "nothing is backed up" — a
        // zero would RESOLVE a firing alert while the system is actually blind.
        $this->connection->executeStatement('DROP TABLE outbox');

        self::assertSame([], $this->provider()->backlogs());
    }
}
