<?php

declare(strict_types=1);

namespace Vortos\Alerts\Escalation;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;

final class DbalMaintenanceSilenceStore implements MaintenanceSilenceStoreInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $table,
    ) {}

    public function add(MaintenanceSilence $silence): void
    {
        $this->connection->insert($this->table, [
            'id' => $silence->id,
            'rule_id' => $silence->ruleId,
            'starts_at' => $silence->startsAt->format(DateTimeImmutable::ATOM),
            'expires_at' => $silence->expiresAt->format(DateTimeImmutable::ATOM),
            'created_by' => $silence->createdBy,
            'reason' => $silence->reason,
        ]);
    }

    public function active(string $ruleId, DateTimeImmutable $now): array
    {
        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT * FROM %s WHERE (rule_id = :rule_id OR rule_id = :wildcard) AND starts_at <= :now AND expires_at > :now',
                $this->table,
            ),
            [
                'rule_id' => $ruleId,
                'wildcard' => '*',
                'now' => $now->format(DateTimeImmutable::ATOM),
            ],
        );

        return array_map($this->fromRow(...), $rows);
    }

    public function purgeExpired(DateTimeImmutable $now): int
    {
        return $this->connection->executeStatement(
            sprintf('DELETE FROM %s WHERE expires_at <= :now', $this->table),
            ['now' => $now->format(DateTimeImmutable::ATOM)],
        );
    }

    /** @param array<string, mixed> $row */
    private function fromRow(array $row): MaintenanceSilence
    {
        return new MaintenanceSilence(
            id: (string) $row['id'],
            ruleId: (string) $row['rule_id'],
            startsAt: new DateTimeImmutable((string) $row['starts_at']),
            expiresAt: new DateTimeImmutable((string) $row['expires_at']),
            createdBy: (string) $row['created_by'],
            reason: (string) $row['reason'],
        );
    }
}
