<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Outbox;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final class ObjectStoreOutboxRetryStore implements ObjectStoreOutboxRetryStoreInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string    $tableName,
    ) {}

    public function countDead(
        ?string             $id          = null,
        ?string             $operation   = null,
        ?\DateTimeImmutable $createdFrom = null,
        ?\DateTimeImmutable $createdTo   = null,
    ): int {
        [$where, $params, $types] = $this->buildWhere($id, $operation, $createdFrom, $createdTo);

        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ' . $this->tableName . ' WHERE ' . $where,
            $params,
            $types,
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function listDead(
        int                 $limit,
        ?string             $id          = null,
        ?string             $operation   = null,
        ?\DateTimeImmutable $createdFrom = null,
        ?\DateTimeImmutable $createdTo   = null,
    ): array {
        [$where, $params, $types] = $this->buildWhere($id, $operation, $createdFrom, $createdTo);

        $params['limit'] = $limit;
        $types['limit']  = ParameterType::INTEGER;

        return $this->connection->fetchAllAssociative(
            'SELECT id, operation, attempt_count, last_error, processed_at, created_at
             FROM ' . $this->tableName . '
             WHERE ' . $where . '
             ORDER BY created_at ASC
             LIMIT :limit',
            $params,
            $types,
        );
    }

    public function resetDead(
        int                 $limit,
        ?string             $id          = null,
        ?string             $operation   = null,
        ?\DateTimeImmutable $createdFrom = null,
        ?\DateTimeImmutable $createdTo   = null,
    ): int {
        [$where, $params, $types] = $this->buildWhere($id, $operation, $createdFrom, $createdTo);

        $params['limit'] = $limit;
        $types['limit']  = ParameterType::INTEGER;

        return (int) $this->connection->executeStatement(
            'UPDATE ' . $this->tableName . '
             SET status = :newStatus, attempt_count = 0,
                 processed_at = NULL, last_error = NULL, next_attempt_at = NULL
             WHERE ' . $where . '
             LIMIT :limit',
            array_merge($params, ['newStatus' => OutboxStatus::Pending->value]),
            $types,
        );
    }

    /** @return array{string, array<string, mixed>, array<string, int>} */
    private function buildWhere(
        ?string             $id,
        ?string             $operation,
        ?\DateTimeImmutable $createdFrom,
        ?\DateTimeImmutable $createdTo,
    ): array {
        $clauses = ['status = :status'];
        $params  = ['status' => OutboxStatus::Dead->value];
        $types   = [];

        if ($id !== null) {
            $clauses[]   = 'id = :id';
            $params['id'] = $id;
        }

        if ($operation !== null) {
            $clauses[]          = 'operation = :operation';
            $params['operation'] = $operation;
        }

        if ($createdFrom !== null) {
            $clauses[]              = 'created_at >= :createdFrom';
            $params['createdFrom']   = $createdFrom->format('Y-m-d H:i:s');
        }

        if ($createdTo !== null) {
            $clauses[]            = 'created_at <= :createdTo';
            $params['createdTo']   = $createdTo->format('Y-m-d H:i:s');
        }

        return [implode(' AND ', $clauses), $params, $types];
    }
}
