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
            return [...$this->deadLetterBacklogs(), ...$this->outboxBacklogs()];
        } catch (Throwable) {
            // A DB hiccup must not take down the whole alert tick. Returning no readings means
            // "nothing evaluated this round", never "nothing is backed up".
            return [];
        }
    }

    /** @return list<QueueBacklog> */
    private function deadLetterBacklogs(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT transport_name,
                    COUNT(*) AS depth,
                    MIN(failed_at) AS oldest
             FROM {$this->deadLetterTable}
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
