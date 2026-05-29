<?php

declare(strict_types=1);

namespace Vortos\PersistenceDbal\Write;

use Doctrine\DBAL\Types\Types;
use Vortos\Domain\Aggregate\AggregateRoot;
use Vortos\Domain\Repository\Exception\OptimisticLockException;

/**
 * PostgreSQL-optimised write repository.
 *
 * Extends DbalWriteRepository with two PostgreSQL-specific batch operations:
 *
 *   batchUpdate() — overrides the base loop with a single UPDATE FROM VALUES query
 *   batchUpsert() — INSERT ... ON CONFLICT (id) DO UPDATE SET for projections and bulk imports
 *
 * Both use DBAL's type system for all column bindings — JSON, integers,
 * and other typed columns are encoded correctly without manual conversion.
 *
 * ## When to use this instead of DbalWriteRepository
 *
 * Use PostgresWriteRepository when:
 *   - Your application runs on PostgreSQL (the default Vortos stack)
 *   - You have use cases that update or upsert large batches of aggregates at once
 *
 * Use DbalWriteRepository when:
 *   - You need database portability (MySQL, SQLite, SQL Server)
 *   - Your batch sizes are small (< 50 aggregates) — the difference is negligible
 *
 * ## All other methods are inherited from DbalWriteRepository
 *
 * save(), delete(), batchInsert(), batchDelete(), batchForceDeleteByIds()
 * behave identically.
 */
abstract class PostgresWriteRepository extends DbalWriteRepository
{
    /**
     * Update multiple aggregates using PostgreSQL's UPDATE FROM VALUES syntax.
     *
     * Executes a single SQL statement regardless of how many aggregates are passed:
     *
     *   UPDATE users SET
     *       email = v.email,
     *       name  = v.name,
     *       version = users.version + 1
     *   FROM (VALUES
     *       ('id-1', 'a@example.com', 'Alice', 1),
     *       ('id-2', 'b@example.com', 'Bob',   2)
     *   ) AS v(id, email, name, version)
     *   WHERE users.id = v.id
     *   AND   users.version = v.version
     *
     * ## Optimistic locking
     *
     * The WHERE clause includes version = v.version — the expected version
     * from each aggregate. If any aggregate has a version mismatch, its row
     * is silently skipped (zero rows affected for that aggregate).
     *
     * Unlike the single save() path, this does NOT throw OptimisticLockException
     * per aggregate — detecting which specific aggregates conflicted requires
     * a follow-up SELECT. For strict per-aggregate conflict detection,
     * use batchUpdate() from DbalWriteRepository (calls save() per aggregate).
     *
     * After execution, incrementVersion() is called on each aggregate.
     *
     * @param AggregateRoot[] $aggregates
     */
    public function batchUpdate(array $aggregates): void
    {
        if (empty($aggregates)) {
            return;
        }

        $types   = $this->columnMap();
        $rows    = array_map(fn(AggregateRoot $a) => $this->toRow($a), $aggregates);
        $columns = array_keys($rows[0]);

        $updateColumns = array_filter(
            $columns,
            fn(string $col) => !in_array($col, ['id', 'version'], true),
        );

        $quotedTable = $this->connection()->quoteIdentifier($this->tableName());

        $setClauses   = array_map(
            fn(string $col) => $this->connection()->quoteIdentifier($col) . ' = v.' . $this->connection()->quoteIdentifier($col),
            $updateColumns,
        );
        $setClauses[] = 'version = ' . $quotedTable . '.version + 1';

        $placeholder       = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $valuePlaceholders = implode(', ', array_fill(0, count($rows), $placeholder));
        $columnAlias       = implode(', ', array_map(
            fn(string $c) => $this->connection()->quoteIdentifier($c),
            $columns,
        ));

        $sql = sprintf(
            'UPDATE %s SET %s FROM (VALUES %s) AS v(%s) WHERE %s.id = v.id AND %s.version = v.version',
            $quotedTable,
            implode(', ', $setClauses),
            $valuePlaceholders,
            $columnAlias,
            $quotedTable,
            $quotedTable,
        );

        $flatValues = [];
        $flatTypes  = [];
        foreach ($rows as $row) {
            foreach ($columns as $col) {
                $flatValues[] = $row[$col];
                $flatTypes[]  = $types[$col] ?? Types::STRING;
            }
        }

        $affected = $this->connection()->executeStatement($sql, $flatValues, $flatTypes);

        if ($affected !== count($aggregates)) {
            throw new OptimisticLockException(sprintf(
                'Batch update conflict: expected %d row(s) affected, got %d. Version mismatch on one or more aggregates.',
                count($aggregates),
                $affected,
            ));
        }

        foreach ($aggregates as $aggregate) {
            $aggregate->incrementVersion();
        }
    }

    /**
     * Insert or update multiple aggregates in a single SQL statement.
     *
     * Uses PostgreSQL's INSERT ... ON CONFLICT (id) DO UPDATE SET syntax.
     * On conflict, all columns except id are updated to the new values.
     * Uses DBAL's type system for all column bindings.
     *
     * WARNING: This method does NOT apply optimistic locking.
     * It will silently overwrite any version in the database.
     * Use only for:
     *   - Read model projections (eventual consistency is acceptable)
     *   - Idempotent bulk imports where last-write-wins is intentional
     *   - Seeding data in tests
     *
     * Never use this for commands where concurrent modification must be detected.
     * After execution, incrementVersion() is called on each aggregate to signal
     * that the aggregate has been persisted.
     *
     * @param AggregateRoot[] $aggregates
     */
    public function batchUpsert(array $aggregates): void
    {
        if (empty($aggregates)) {
            return;
        }

        $types   = $this->columnMap();
        $rows    = array_map(fn(AggregateRoot $a) => $this->toRow($a), $aggregates);
        $columns = array_keys($rows[0]);

        $placeholder  = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $placeholders = implode(', ', array_fill(0, count($rows), $placeholder));

        $quotedTable   = $this->connection()->quoteIdentifier($this->tableName());
        $quotedColumns = implode(', ', array_map(
            fn(string $c) => $this->connection()->quoteIdentifier($c),
            $columns,
        ));

        $setClauses = implode(', ', array_map(
            fn(string $col) => $this->connection()->quoteIdentifier($col) . ' = EXCLUDED.' . $this->connection()->quoteIdentifier($col),
            array_filter($columns, fn(string $col) => $col !== 'id'),
        ));

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES %s ON CONFLICT (id) DO UPDATE SET %s',
            $quotedTable,
            $quotedColumns,
            $placeholders,
            $setClauses,
        );

        $flatValues = [];
        $flatTypes  = [];
        foreach ($rows as $row) {
            foreach ($columns as $col) {
                $flatValues[] = $row[$col];
                $flatTypes[]  = $types[$col] ?? Types::STRING;
            }
        }

        $this->connection()->executeStatement($sql, $flatValues, $flatTypes);

        foreach ($aggregates as $aggregate) {
            $aggregate->incrementVersion();
        }
    }
}
