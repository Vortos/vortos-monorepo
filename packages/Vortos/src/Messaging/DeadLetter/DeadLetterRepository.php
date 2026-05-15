<?php

declare(strict_types=1);

namespace Vortos\Messaging\DeadLetter;

use DateTimeInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final class DeadLetterRepository
{
    public function __construct(
        private Connection $connection,
        private string $table = 'vortos_failed_messages'
    ) {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $this->table)) {
            throw new \InvalidArgumentException(sprintf('Invalid table name "%s".', $this->table));
        }
    }

    public function fetchFailed(
        int $limit = 50,
        ?string $transport = null,
        ?string $eventClass = null,
        ?string $id = null,
        bool $orderDesc = false,
        ?DateTimeInterface $failedFrom = null,
        ?DateTimeInterface $failedTo = null,
    ): array {
        $sql    = "SELECT * FROM {$this->table} WHERE status = 'failed'";
        $params = ['limit' => $limit];
        $types  = ['limit' => ParameterType::INTEGER];

        if ($id !== null) {
            $sql .= ' AND id = :id';
            $params['id'] = $id;
        }

        if ($transport !== null) {
            $sql .= ' AND transport_name = :transport';
            $params['transport'] = $transport;
        }

        if ($eventClass !== null) {
            $sql .= ' AND event_class = :event_class';
            $params['event_class'] = $eventClass;
        }

        if ($failedFrom !== null) {
            $sql .= ' AND failed_at >= :failed_from';
            $params['failed_from'] = $failedFrom->format('Y-m-d H:i:s');
        }

        if ($failedTo !== null) {
            $sql .= ' AND failed_at <= :failed_to';
            $params['failed_to'] = $failedTo->format('Y-m-d H:i:s');
        }

        $sql .= $orderDesc ? ' ORDER BY failed_at DESC LIMIT :limit' : ' ORDER BY failed_at ASC LIMIT :limit';

        return $this->connection->fetchAllAssociative($sql, $params, $types);
    }

    public function markReplayed(string $id): void
    {
        $this->connection->update($this->table, [
            'status'      => 'replayed',
            'replayed_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }
}
