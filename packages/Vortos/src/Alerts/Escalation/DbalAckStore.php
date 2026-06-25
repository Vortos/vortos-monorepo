<?php

declare(strict_types=1);

namespace Vortos\Alerts\Escalation;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;

final class DbalAckStore implements AckStoreInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $table,
    ) {}

    public function record(Acknowledgement $ack): void
    {
        $this->connection->transactional(function (Connection $conn) use ($ack): void {
            $exists = $conn->fetchOne(
                sprintf('SELECT fingerprint FROM %s WHERE fingerprint = :fingerprint', $this->table),
                ['fingerprint' => $ack->fingerprint],
            );

            $row = [
                'fingerprint' => $ack->fingerprint,
                'tier' => $ack->tier,
                'acked_by' => $ack->ackedBy,
                'acked_at' => $ack->ackedAt->format(DateTimeImmutable::ATOM),
            ];

            if ($exists === false) {
                $conn->insert($this->table, $row);

                return;
            }

            $conn->update($this->table, $row, ['fingerprint' => $ack->fingerprint]);
        });
    }

    public function find(string $fingerprint): ?Acknowledgement
    {
        $row = $this->connection->fetchAssociative(
            sprintf('SELECT * FROM %s WHERE fingerprint = :fingerprint', $this->table),
            ['fingerprint' => $fingerprint],
        );

        if ($row === false) {
            return null;
        }

        return new Acknowledgement(
            fingerprint: (string) $row['fingerprint'],
            tier: (int) $row['tier'],
            ackedBy: (string) $row['acked_by'],
            ackedAt: new DateTimeImmutable((string) $row['acked_at']),
        );
    }
}
