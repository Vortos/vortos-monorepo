<?php

declare(strict_types=1);

namespace Vortos\Domain\Repository;

/**
 * Contract for read model repositories.
 *
 * Read repositories return typed read model DTOs, never domain aggregates.
 * The read side is optimized for query performance, not transactional integrity.
 *
 * Implementations: MongoStore-backed repositories (MongoDB), DbalReadRepository (PostgreSQL).
 *
 * @template T The read model type returned by this repository
 */
interface ReadRepositoryInterface
{
    /**
     * Find a single read model by ID.
     *
     * @return T|null null if not found
     */
    public function findById(string $id): mixed;

    /**
     * Find read models matching criteria.
     *
     * $criteria is a flat key-value equality filter.
     * $sort is ['field' => 'asc'|'desc'].
     *
     * @return list<T>
     */
    public function findByCriteria(
        array $criteria,
        array $sort = [],
        int $limit = 50,
        ?string $cursor = null,
    ): array;

    /**
     * Find a keyset-paginated page of read models.
     *
     * @return PageResult<T>
     */
    public function findPage(
        array $criteria,
        int $limit,
        ?string $cursor = null,
        array $sort = [],
    ): PageResult;

    /**
     * Count records matching criteria.
     */
    public function countByCriteria(array $criteria): int;
}