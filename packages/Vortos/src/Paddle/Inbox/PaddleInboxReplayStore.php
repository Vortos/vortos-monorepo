<?php

declare(strict_types=1);

namespace Vortos\Paddle\Inbox;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final class PaddleInboxReplayStore implements PaddleInboxReplayStoreInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string     $table,
    ) {}

    public function countDead(?int $id = null, ?string $eventType = null): int
    {
        [$where, $params, $types] = $this->buildWhere($id, $eventType);

        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ' . $this->table . ' WHERE ' . $where,
            $params,
            $types,
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function listDead(int $limit, ?int $id = null, ?string $eventType = null): array
    {
        [$where, $params, $types] = $this->buildWhere($id, $eventType);

        $params['limit'] = $limit;
        $types['limit']  = ParameterType::INTEGER;

        return $this->connection->fetchAllAssociative(
            'SELECT id, event_id, event_type, attempts, last_error, received_at
             FROM ' . $this->table . '
             WHERE ' . $where . '
             ORDER BY received_at ASC
             LIMIT :limit',
            $params,
            $types,
        );
    }

    public function replayDead(int $limit, ?int $id = null, ?string $eventType = null): int
    {
        [$where, $params, $types] = $this->buildWhere($id, $eventType);

        $params['now']   = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $params['limit'] = $limit;
        $types['limit']  = ParameterType::INTEGER;

        return (int) $this->connection->executeStatement(
            'UPDATE ' . $this->table . '
             SET status = :newStatus, attempts = 0, last_error = NULL, next_attempt_at = :now
             WHERE ' . $where . '
             LIMIT :limit',
            array_merge($params, ['newStatus' => InboxStatus::Pending->value]),
            $types,
        );
    }

    /** @return array{string, array<string, mixed>, array<string, int>} */
    private function buildWhere(?int $id, ?string $eventType): array
    {
        $clauses = ['status = :status'];
        $params  = ['status' => InboxStatus::Dead->value];
        $types   = [];

        if ($id !== null) {
            $clauses[]    = 'id = :id';
            $params['id'] = $id;
            $types['id']  = ParameterType::INTEGER;
        }

        if ($eventType !== null) {
            $clauses[]            = 'event_type = :eventType';
            $params['eventType']  = $eventType;
        }

        return [implode(' AND ', $clauses), $params, $types];
    }
}
