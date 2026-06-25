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
        );
    }
}
