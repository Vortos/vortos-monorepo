<?php

declare(strict_types=1);

namespace Vortos\Alerts\Dedupe;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;

/**
 * Default (prod) state store, table `vortos_alerts_state`. Single-writer per
 * fingerprint via a Postgres advisory transaction lock — a no-op on SQLite, where
 * DBAL's own transaction already serializes writers on the single test connection
 * (same discipline as `DbalDeployAuditViewRepository`).
 */
final class DbalAlertStateStore implements AlertStateStoreInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $table,
    ) {}

    public function get(string $fingerprint): ?AlertState
    {
        $row = $this->connection->fetchAssociative(
            sprintf('SELECT * FROM %s WHERE fingerprint = :fingerprint', $this->table),
            ['fingerprint' => $fingerprint],
        );

        return $row === false ? null : $this->fromRow($row);
    }

    /** @return list<AlertState> */
    public function openSince(\DateTimeImmutable $threshold): array
    {
        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT * FROM %s WHERE status = :status AND last_seen_at < :threshold',
                $this->table,
            ),
            [
                'status' => AlertStateStatus::Open->value,
                'threshold' => $threshold->format(DateTimeImmutable::ATOM),
            ],
        );

        return array_map(fn (array $row): AlertState => $this->fromRow($row), $rows);
    }

    public function hasActiveRuleSince(string $ruleId, \DateTimeImmutable $threshold): bool
    {
        // Any open alert for this rule, seen at or after the threshold, means the rule is firing
        // right now. Indexed on rule_id (see the state_rule_id migration) so this stays a lookup,
        // not a scan, on every dispatch. `>=` not `>`: a source that fired at exactly the threshold
        // is active — the boundary belongs to "still firing", the inhibiting side.
        $found = $this->connection->fetchOne(
            sprintf(
                'SELECT 1 FROM %s WHERE rule_id = :ruleId AND status = :status AND last_seen_at >= :threshold LIMIT 1',
                $this->table,
            ),
            [
                'ruleId' => $ruleId,
                'status' => AlertStateStatus::Open->value,
                'threshold' => $threshold->format(DateTimeImmutable::ATOM),
            ],
        );

        return $found !== false;
    }

    public function save(AlertState $state): void
    {
        $this->connection->transactional(function (Connection $conn) use ($state): void {
            if ($conn->getDatabasePlatform() instanceof PostgreSQLPlatform) {
                $conn->executeStatement('SELECT pg_advisory_xact_lock(hashtext(:fingerprint))', ['fingerprint' => $state->fingerprint]);
            }

            $existing = $conn->fetchOne(
                sprintf('SELECT fingerprint FROM %s WHERE fingerprint = :fingerprint', $this->table),
                ['fingerprint' => $state->fingerprint],
            );

            $row = $this->toRow($state);

            if ($existing === false) {
                $conn->insert($this->table, $row);

                return;
            }

            $conn->update($this->table, $row, ['fingerprint' => $state->fingerprint]);
        });
    }

    /** @return array<string, mixed> */
    private function toRow(AlertState $state): array
    {
        return [
            'fingerprint' => $state->fingerprint,
            'status' => $state->status->value,
            'first_seen_at' => $state->firstSeenAt->format(DateTimeImmutable::ATOM),
            'last_seen_at' => $state->lastSeenAt->format(DateTimeImmutable::ATOM),
            'occurrence_count' => $state->occurrenceCount,
            'flap_transitions' => $state->flapTransitions,
            'flap_window_start_at' => $state->flapWindowStartAt?->format(DateTimeImmutable::ATOM),
            'flap_escalated_at' => $state->flapEscalatedAt?->format(DateTimeImmutable::ATOM),
            // Backoff state must be durable: if it resets on restart, "still firing" reminders go
            // back to full volume — which on a blue/green deploy is several times a day.
            'last_notified_at' => $state->lastNotifiedAt?->format(DateTimeImmutable::ATOM),
            'reminder_count' => $state->reminderCount,
            // The rule this state belongs to — what inhibition's "is the source firing?" lookup keys on.
            'rule_id' => $state->ruleId,
        ];
    }

    /** @param array<string, mixed> $row */
    private function fromRow(array $row): AlertState
    {
        return new AlertState(
            fingerprint: (string) $row['fingerprint'],
            status: AlertStateStatus::from((string) $row['status']),
            firstSeenAt: new DateTimeImmutable((string) $row['first_seen_at']),
            lastSeenAt: new DateTimeImmutable((string) $row['last_seen_at']),
            occurrenceCount: (int) $row['occurrence_count'],
            flapTransitions: (int) $row['flap_transitions'],
            flapWindowStartAt: $row['flap_window_start_at'] !== null ? new DateTimeImmutable((string) $row['flap_window_start_at']) : null,
            flapEscalatedAt: $row['flap_escalated_at'] !== null ? new DateTimeImmutable((string) $row['flap_escalated_at']) : null,
            lastNotifiedAt: isset($row['last_notified_at']) ? new DateTimeImmutable((string) $row['last_notified_at']) : null,
            reminderCount: (int) ($row['reminder_count'] ?? 0),
            ruleId: isset($row['rule_id']) ? (string) $row['rule_id'] : null,
        );
    }
}
