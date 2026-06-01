<?php

declare(strict_types=1);

namespace Vortos\Paddle\Outbox;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final class PaddleOutboxRetryStore implements PaddleOutboxRetryStoreInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string    $table,
    ) {}

    public function countFailed(?int $id = null, ?string $operation = null): int
    {
        [$where, $params, $types] = $this->buildWhere($id, $operation);

        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ' . $this->table . ' WHERE ' . $where,
            $params,
            $types,
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function listFailed(int $limit, ?int $id = null, ?string $operation = null): array
    {
        [$where, $params, $types] = $this->buildWhere($id, $operation);

        $params['limit'] = $limit;
        $types['limit']  = ParameterType::INTEGER;

        return $this->connection->fetchAllAssociative(
            'SELECT id, operation, attempts, last_error, failed_at, created_at
             FROM ' . $this->table . '
             WHERE ' . $where . '
             ORDER BY created_at ASC
             LIMIT :limit',
            $params,
            $types,
        );
    }

    public function resetFailed(int $limit, ?int $id = null, ?string $operation = null): int
    {
        [$where, $params, $types] = $this->buildWhere($id, $operation);

        $now             = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $params['now']   = $now;
        $params['limit'] = $limit;
        $types['limit']  = ParameterType::INTEGER;

        return (int) $this->connection->executeStatement(
            'UPDATE ' . $this->table . '
             SET status = :newStatus, attempts = 0, failed_at = NULL,
                 delivered_at = NULL, last_error = NULL, next_attempt_at = :now
             WHERE ' . $where . '
             LIMIT :limit',
            array_merge($params, ['newStatus' => OutboxStatus::Pending->value]),
            $types,
        );
    }

    /** @return array{string, array<string, mixed>, array<string, int>} */
    private function buildWhere(?int $id, ?string $operation): array
    {
        $clauses = ['status = :status'];
        $params  = ['status' => OutboxStatus::Failed->value];
        $types   = [];

        if ($id !== null) {
            $clauses[]   = 'id = :id';
            $params['id'] = $id;
            $types['id']  = ParameterType::INTEGER;
        }

        if ($operation !== null) {
            $clauses[]          = 'operation = :operation';
            $params['operation'] = $operation;
        }

        return [implode(' AND ', $clauses), $params, $types];
    }
}
