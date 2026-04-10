<?php

namespace Vortos\Domain\Repository;

/**
 * Contract for read model repositories.
 *
 * Read repositories return raw arrays or ViewModels, never domain aggregates.
 * The read side is optimized for query performance, not transactional integrity.
 *
 * The default implementation is MongoReadRepository in vortos-persistence.
 * A DBAL implementation is available for pure relational read stacks.
 * InMemoryReadRepository ships for testing.
 */
interface ReadRepositoryInterface
{
    /**
     * Find a single read model by ID.
     * Returns null if not found.
     */
    public function findById(string $id): ?array;

    /**
     * Find multiple read models matching criteria.
     * 
     * $criteria is a key-value filter map.
     * $sort is ['field' => 'asc'|'desc'].
     * $limit caps the result set.
     * $offset enables pagination.
     * 
     * Returns raw arrays — mapping to ViewModels is the caller's responsibility.
     */
    public function findByCriteria(
        array $criteria,
        array $sort = [],
        int $limit = 50,
        ?string $cursor = null,
    ): array;

    public function findPage(
        array $criteria,
        int $limit,
        ?string $cursor = null,
        array $sort = [],
    ): PageResult;

    /**
     * Count records matching criteria.
     * Use for pagination metadata without fetching full result sets.
     */
    public function countByCriteria(array $criteria): int;
}