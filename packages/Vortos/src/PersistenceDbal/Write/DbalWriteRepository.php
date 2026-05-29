<?php

declare(strict_types=1);

namespace Vortos\PersistenceDbal\Write;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Vortos\Domain\Aggregate\AggregateRoot;
use Vortos\Domain\Identity\AggregateId;
use Vortos\Domain\Repository\Exception\AggregateNotFoundException;
use Vortos\Domain\Repository\Exception\OptimisticLockException;
use Vortos\Domain\Repository\WriteRepositoryInterface;

/**
 * Abstract DBAL-backed write repository.
 *
 * Provides all standard persistence operations free.
 * You implement 4 methods describing your table shape.
 * The base handles insert, update, delete, batch operations, and optimistic locking.
 *
 * ## This is NOT an ORM
 *
 * No identity map. No change tracking. No lazy loading. No proxy objects.
 * All SQL is explicit. What you see is what executes.
 *
 * ## Required table structure
 *
 * Every table using this base MUST have these columns:
 *
 *   id      UUID or VARCHAR(36)  PRIMARY KEY
 *   version INTEGER              NOT NULL DEFAULT 0
 *
 * The version column is used for optimistic concurrency control.
 * If your table lacks these columns, save() will behave incorrectly.
 *
 * ## Implementing the 4 required methods
 *
 *   final class UserRepository extends DbalWriteRepository
 *   {
 *       protected function tableName(): string
 *       {
 *           return 'users';
 *       }
 *
 *       protected function columnMap(): array
 *       {
 *           return [
 *               'id'      => Types::STRING,
 *               'email'   => Types::STRING,
 *               'name'    => Types::STRING,
 *               'version' => Types::INTEGER,
 *           ];
 *       }
 *
 *       protected function toRow(AggregateRoot $aggregate): array
 *       {
 *           /** @var User $aggregate
 *           return [
 *               'id'      => (string) $aggregate->getId(),
 *               'email'   => (string) $aggregate->email,
 *               'name'    => $aggregate->name,
 *               'version' => $aggregate->getVersion(),
 *           ];
 *       }
 *
 *       protected function fromRow(array $row): AggregateRoot
 *       {
 *           return User::reconstruct(
 *               UserId::fromString($row['id']),
 *               $row['email'],
 *               $row['name'],
 *               (int) $row['version'],
 *           );
 *       }
 *   }
 *
 * ## Custom queries
 *
 * Use the protected connection() method for custom queries:
 *
 *   public function findByEmail(Email $email): ?User
 *   {
 *       $row = $this->connection()->createQueryBuilder()
 *           ->select('*')
 *           ->from($this->tableName())
 *           ->where('email = :email')
 *           ->setParameter('email', (string) $email)
 *           ->executeQuery()
 *           ->fetchAssociative();
 *
 *       return $row ? $this->fromRow($row) : null;
 *   }
 *
 * ## Optimistic locking
 *
 * save() uses the version column to detect concurrent modifications.
 * If two processes load the same aggregate and both call save(),
 * the second save will throw OptimisticLockException because the
 * version in the database no longer matches the expected version.
 *
 * Your ApplicationService should catch OptimisticLockException and
 * either retry (with fresh load) or return a conflict error to the caller.
 *
 * ## Batch operations
 *
 * batchInsert()         — single INSERT with multiple VALUES rows; all aggregates must be new
 * batchUpdate()         — loops save() per aggregate; applies optimistic locking on each
 * batchDelete()         — loops delete() per aggregate; applies optimistic locking on each
 * batchForceDeleteByIds() — single DELETE IN (:ids); bypasses version checks entirely
 *
 * PostgresWriteRepository adds batchUpsert() (INSERT ... ON CONFLICT) and overrides
 * batchUpdate() with a single UPDATE FROM VALUES query.
 */
abstract class DbalWriteRepository implements WriteRepositoryInterface
{
    public function __construct(private readonly Connection $connection) {}

    /**
     * The database table name for this repository.
     *
     * Return the plain table name without schema prefix.
     * Example: 'users', 'orders', 'competition_entries'
     */
    abstract protected function tableName(): string;

    /**
     * Map of column names to DBAL Types constants.
     *
     * Used for type-safe parameter binding in all generated queries.
     * MUST include 'version' => Types::INTEGER.
     *
     * Example:
     *   return [
     *       'id'      => Types::STRING,
     *       'email'   => Types::STRING,
     *       'version' => Types::INTEGER,
     *   ];
     *
     * @return array<string, string>
     */
    abstract protected function columnMap(): array;

    /**
     * Map an aggregate to a flat database row array.
     *
     * Keys must exactly match the column names in columnMap().
     * Include 'version' => $aggregate->getVersion() — the base class
     * uses this for optimistic lock checks, but never writes it directly
     * as a SET value in UPDATE queries (the DB does version + 1 instead).
     *
     * Do NOT call incrementVersion() here — the base handles that
     * after a successful save.
     *
     * @return array<string, mixed>
     */
    abstract protected function toRow(AggregateRoot $aggregate): array;

    /**
     * Reconstruct an aggregate from a flat database row array.
     *
     * Must restore the version field. Your aggregate needs a way to
     * accept a version on reconstruction — typically a static factory
     * method or a dedicated reconstruct() named constructor.
     *
     * Example:
     *   return User::reconstruct(
     *       UserId::fromString($row['id']),
     *       $row['email'],
     *       (int) $row['version'],
     *   );
     *
     * @param array<string, mixed> $row
     */
    abstract protected function fromRow(array $row): AggregateRoot;

    /**
     * Persist an aggregate — handles both insert and update.
     *
     * ## Insert vs Update detection
     *
     * Uses AggregateRoot::isNew() to distinguish new aggregates from existing ones.
     * New aggregates (never saved or reconstructed) → INSERT.
     * Existing aggregates (previously saved or loaded from DB) → UPDATE with optimistic lock check.
     *
     * ## Optimistic locking on UPDATE
     *
     * The UPDATE WHERE clause includes: AND version = :expectedVersion
     * If zero rows are affected, another process modified the aggregate
     * between your load and this save. OptimisticLockException is thrown.
     *
     * ## Version increment
     *
     * After a successful save (insert or update), incrementVersion() is called
     * on the aggregate. This keeps the in-memory aggregate in sync with the
     * database version, so subsequent saves in the same request work correctly.
     *
     * {@inheritdoc}
     */
    public function save(AggregateRoot $aggregate): void
    {
        $row = $this->toRow($aggregate);

        if ($aggregate->isNew()) {
            $this->connection->insert($this->tableName(), $row, $this->columnMap());
            $aggregate->incrementVersion();
            return;
        }

        $expectedVersion = $aggregate->getVersion();

        unset($row['version']);

        $qb    = $this->connection->createQueryBuilder();
        $types = $this->columnMap();

        $qb->update($this->tableName());

        foreach ($row as $column => $value) {
            $qb->set($column, ':' . $column);
            $qb->setParameter($column, $value, $types[$column] ?? null);
        }

        $qb->set('version', 'version + 1')
            ->where('id = :id')
            ->andWhere('version = :expectedVersion')
            ->setParameter('id', (string) $aggregate->getId())
            ->setParameter('expectedVersion', $expectedVersion);

        $affected = $qb->executeStatement();

        if ($affected === 0) {
            throw OptimisticLockException::forAggregate(
                get_class($aggregate),
                (string) $aggregate->getId(),
                $expectedVersion,
                -1,
            );
        }

        $aggregate->incrementVersion();
    }

    /**
     * Remove an aggregate from the store.
     *
     * Applies optimistic locking on delete — prevents deleting an aggregate
     * that has been modified since you loaded it.
     *
     * If the aggregate does not exist, throws AggregateNotFoundException.
     * If it exists but the version does not match, throws OptimisticLockException.
     * The second check is a follow-up SELECT only on the failure path — zero
     * overhead on the happy path.
     *
     * {@inheritdoc}
     */
    public function delete(AggregateRoot $aggregate): void
    {
        $affected = $this->connection->createQueryBuilder()
            ->delete($this->tableName())
            ->where('id = :id')
            ->andWhere('version = :version')
            ->setParameter('id', (string) $aggregate->getId())
            ->setParameter('version', $aggregate->getVersion())
            ->executeStatement();

        if ($affected === 0) {
            $exists = (bool) $this->connection->createQueryBuilder()
                ->select('1')
                ->from($this->tableName())
                ->where('id = :id')
                ->setParameter('id', (string) $aggregate->getId())
                ->executeQuery()
                ->fetchOne();

            if (!$exists) {
                throw AggregateNotFoundException::for(get_class($aggregate), (string) $aggregate->getId());
            }

            throw OptimisticLockException::forAggregate(
                get_class($aggregate),
                (string) $aggregate->getId(),
                $aggregate->getVersion(),
                -1,
            );
        }
    }

    /**
     * Insert multiple aggregates in a single SQL statement.
     *
     * More efficient than calling save() in a loop for bulk inserts.
     * Builds one INSERT with multiple VALUES rows and executes once.
     * Uses DBAL's type system for all columns — JSON, integers, and other
     * types are encoded correctly without manual conversion.
     *
     * All aggregates must be new (isNew() === true). For updating existing
     * aggregates in bulk, use batchUpdate().
     *
     * After successful insert, incrementVersion() is called on each aggregate.
     *
     * @param AggregateRoot[] $aggregates
     */
    public function batchInsert(array $aggregates): void
    {
        if (empty($aggregates)) {
            return;
        }

        $types   = $this->columnMap();
        $rows    = array_map(fn(AggregateRoot $a) => $this->toRow($a), $aggregates);
        $columns = array_keys($rows[0]);

        $placeholder  = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $placeholders = implode(', ', array_fill(0, count($rows), $placeholder));

        $quotedTable   = $this->connection->quoteIdentifier($this->tableName());
        $quotedColumns = implode(', ', array_map(
            fn(string $c) => $this->connection->quoteIdentifier($c),
            $columns,
        ));

        $sql = sprintf('INSERT INTO %s (%s) VALUES %s', $quotedTable, $quotedColumns, $placeholders);

        $flatValues = [];
        $flatTypes  = [];
        foreach ($rows as $row) {
            foreach ($columns as $col) {
                $flatValues[] = $row[$col];
                $flatTypes[]  = $types[$col] ?? Types::STRING;
            }
        }

        $this->connection->executeStatement($sql, $flatValues, $flatTypes);

        foreach ($aggregates as $aggregate) {
            $aggregate->incrementVersion();
        }
    }

    /**
     * Update multiple aggregates by calling save() on each.
     *
     * Each save() applies optimistic locking individually.
     * This is the safe generic implementation — it works across all databases.
     *
     * For PostgreSQL-specific bulk UPDATE FROM VALUES (more efficient at scale),
     * extend PostgresWriteRepository instead, which overrides this method
     * with a single-query implementation.
     *
     * @param AggregateRoot[] $aggregates
     */
    public function batchUpdate(array $aggregates): void
    {
        foreach ($aggregates as $aggregate) {
            $this->save($aggregate);
        }
    }

    /**
     * Delete multiple aggregates, applying optimistic locking on each.
     *
     * Calls delete() per aggregate — throws AggregateNotFoundException or
     * OptimisticLockException on the first failure. Use batchForceDeleteByIds()
     * when you need a single-query delete without version checks.
     *
     * @param AggregateRoot[] $aggregates
     */
    public function batchDelete(array $aggregates): void
    {
        foreach ($aggregates as $aggregate) {
            $this->delete($aggregate);
        }
    }

    /**
     * Delete multiple aggregates by ID in a single SQL statement.
     *
     * Uses DELETE WHERE id IN (:ids) — one query regardless of count.
     * Does NOT apply optimistic locking and does NOT throw if an ID is missing.
     *
     * Use only when you are certain the aggregates have not been concurrently
     * modified and when last-write-wins semantics are acceptable — e.g. cascading
     * deletes, test teardown, or administrative bulk removal.
     *
     * @param AggregateId[] $ids
     */
    public function batchForceDeleteByIds(array $ids): void
    {
        if (empty($ids)) {
            return;
        }

        $stringIds = array_map(fn(AggregateId $id) => (string) $id, $ids);

        $this->connection->createQueryBuilder()
            ->delete($this->tableName())
            ->where('id IN (:ids)')
            ->setParameter('ids', $stringIds, ArrayParameterType::STRING)
            ->executeStatement();
    }

    protected function find(AggregateId $id): ?AggregateRoot
    {
        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from($this->tableName())
            ->where('id = :id')
            ->setParameter('id', (string) $id)
            ->executeQuery()
            ->fetchAssociative();

        return $row !== false ? $this->fromRow($row) : null;
    }

    protected function connection(): Connection
    {
        return $this->connection;
    }
}
