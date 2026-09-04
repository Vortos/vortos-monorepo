<?php

declare(strict_types=1);

namespace Vortos\Messaging\Integration\Alerts;

use Doctrine\DBAL\Connection;
use Throwable;
use Vortos\Alerts\Integration\Messaging\QueueBacklog;
use Vortos\Alerts\Integration\Messaging\QueueBacklogProviderInterface;

/**
 * Supplies outbox and dead-letter backlog readings to vortos-alerts.
 *
 * Implements a seam vortos-alerts declares, so the dependency points Messaging → Alerts and Alerts
 * stays free of any messaging knowledge.
 *
 * Reports depth AND the age of the oldest row, because depth alone hides the worst failure: a queue
 * sitting at a constant three messages looks stable, but if they are the same three from six hours
 * ago then nothing is draining at all. Age is what distinguishes a flowing queue from a stuck one.
 *
 * Queue names are prefixed ("dlq:kafka", "outbox:kafka") so an alert rule can target one surface
 * without colliding with a transport of the same name on the other.
 */
final class DbalQueueBacklogProvider implements QueueBacklogProviderInterface
{
    private readonly string $outboxTable;
    private readonly string $deadLetterTable;

    public function __construct(
        private readonly Connection $connection,
        string $outboxTable = 'vortos_outbox',
        string $deadLetterTable = 'vortos_failed_messages',
    ) {
        $this->outboxTable = $this->quoteQualifiedIdentifier($outboxTable);
        $this->deadLetterTable = $this->quoteQualifiedIdentifier($deadLetterTable);
    }

    public function name(): string
    {
        return 'messaging';
    }

    /** @return list<QueueBacklog> */
    public function backlogs(): array
    {
        try {
            return [
                ...$this->deadLetterBacklogs(),
                ...$this->outboxBacklogs(),
                ...$this->exhaustedOutboxBacklogs(),
            ];
        } catch (Throwable) {
            // A DB hiccup must not take down the whole alert tick. Returning no readings means
            // "nothing evaluated this round", never "nothing is backed up".
            return [];
        }
    }

    /**
     * Still-failed rows only. A replayed dead letter is history, not backlog.
     *
     * This counted every row in the table, forever. Replaying a dead letter sets status='replayed'
     * and stamps replayed_at, but the row stays for audit — so the gauge only ever climbed, and a
     * `dlq-not-empty` rule with the obvious threshold of zero could never resolve again once
     * anything had ever been dead-lettered.
     *
     * Production ran that way: 21 messages dead-lettered in July 2026 were replayed successfully,
     * and the alert went on paging for weeks afterwards against 21 rows of history. `vortos:dlq:list`
     * showed an empty queue throughout, because {@see DeadLetterRepository} filters on
     * status = 'failed' — so the tool operators check and the alarm that pages them disagreed, and
     * the alarm was the one that was wrong.
     *
     * The predicate is the repository's, deliberately, so "what the DLQ contains" has one answer.
     * {@see outboxBacklogs()} below already applied exactly this rule to its own surface.
     *
     * @return list<QueueBacklog>
     */
    private function deadLetterBacklogs(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT transport_name,
                    COUNT(*) AS depth,
                    MIN(failed_at) AS oldest
             FROM {$this->deadLetterTable}
             WHERE status = 'failed'
             GROUP BY transport_name",
        );

        return $this->toBacklogs($rows, 'dlq');
    }

    /**
     * Pending only. A delivered outbox row is history, not backlog — counting it would make the
     * gauge climb forever and every threshold meaningless.
     *
     * @return list<QueueBacklog>
     */
    private function outboxBacklogs(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT transport_name,
                    COUNT(*) AS depth,
                    MIN(created_at) AS oldest
             FROM {$this->outboxTable}
             WHERE status = 'pending'
             GROUP BY transport_name",
        );

        return $this->toBacklogs($rows, 'outbox');
    }

    /**
     * Rows that exhausted max_attempts and stopped — the blind spot between the other two readings.
     *
     * A row in this state is not `pending`, so the outbox gauge above excludes it, and it never
     * reached the dead-letter table either. It is simply a message the system accepted, gave up on,
     * and then stopped mentioning. Nothing measured it.
     *
     * This is not a theoretical gap. Production held 11 such rows for two weeks — three of them
     * PaymentCompleted, the rest invitation events — and every dashboard was green the entire time,
     * because "failed" is a terminal state and terminal states stop moving. Depth is the right
     * measure here and age is not: these rows never drain, so their age only grows and any age
     * threshold would fire forever once tripped.
     *
     * @return list<QueueBacklog>
     */
    private function exhaustedOutboxBacklogs(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT transport_name,
                    COUNT(*) AS depth,
                    MIN(created_at) AS oldest
             FROM {$this->outboxTable}
             WHERE status = 'failed'
             GROUP BY transport_name",
        );

        return $this->toBacklogs($rows, 'outbox-failed');
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<QueueBacklog>
     */
    private function toBacklogs(array $rows, string $prefix): array
    {
        $backlogs = [];

        foreach ($rows as $row) {
            $backlogs[] = new QueueBacklog(
                queue: $prefix . ':' . (string) ($row['transport_name'] ?? 'unknown'),
                depth: (int) ($row['depth'] ?? 0),
                oldestAgeSeconds: $this->ageSeconds($row['oldest'] ?? null),
            );
        }

        return $backlogs;
    }

    private function ageSeconds(mixed $timestamp): ?int
    {
        if (!is_string($timestamp) || $timestamp === '') {
            return null;
        }

        $parsed = strtotime($timestamp);

        if ($parsed === false) {
            return null;
        }

        return max(0, time() - $parsed);
    }

    /**
     * Validate and ANSI-quote a bare or schema-qualified table identifier. Mirrors the guard in
     * OperationalMessagingMetricsCollector: every dot-separated segment must be a bare SQL
     * identifier, so operator-supplied table names can never carry injection.
     */
    private function quoteQualifiedIdentifier(string $identifier): string
    {
        $segments = explode('.', $identifier);
        $quoted = [];

        foreach ($segments as $segment) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $segment) !== 1) {
                throw new \InvalidArgumentException(
                    'Queue backlog table names must be safe SQL identifiers.',
                );
            }

            $quoted[] = '"' . $segment . '"';
        }

        return implode('.', $quoted);
    }
}
