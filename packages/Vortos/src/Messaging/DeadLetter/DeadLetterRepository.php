<?php

declare(strict_types=1);

namespace Vortos\Messaging\DeadLetter;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final class DeadLetterRepository
{
    public function __construct(
        private Connection $connection,
        private string $table = 'vortos_failed_messages'
    ) {}

    public function fetchFailed(
        int $limit = 50,
        ?string $transport = null,
        ?string $eventClass = null,
        ?string $id = null,
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

        $sql .= ' ORDER BY failed_at ASC LIMIT :limit';

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
