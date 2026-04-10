<?php

declare(strict_types=1);

namespace Vortos\Persistence\Read;

use Vortos\Domain\Repository\PageResult;
use Vortos\Domain\Repository\ReadRepositoryInterface;

/**
 * In-memory read repository for testing.
 *
 * Provides all ReadRepositoryInterface methods using a plain PHP array.
 * No MongoDB, no network, no external dependencies.
 *
 * ## Criteria matching
 *
 * Criteria matching is strict equality only — no MongoDB operators ($gt, $in, etc).
 * This is intentional: tests should use simple, predictable data.
 * If your production query uses complex operators, write a dedicated
 * integration test against real MongoDB instead of trying to replicate
 * operator behaviour in InMemory.
 *
 * ## Cursor pagination
 *
 * The cursor is a base64-encoded integer representing the offset position
 * in the filtered and sorted result array. This is simpler than MongoDB's
 * field-value cursor but sufficient for testing pagination logic.
 *
 * ## Usage in tests
 *
 *   final class InMemoryUserReadRepository extends InMemoryReadRepository {}
 *
 *   $repository = new InMemoryUserReadRepository();
 *   $repository->seed('user-1', ['_id' => 'user-1', 'email' => 'a@example.com', 'name' => 'Alice']);
 *   $repository->seed('user-2', ['_id' => 'user-2', 'email' => 'b@example.com', 'name' => 'Bob']);
 *
 *   $result = $repository->findByCriteria(['name' => 'Alice']);
 *   $this->assertCount(1, $result);
 *
 *   $repository->clear();
 */
abstract class InMemoryReadRepository implements ReadRepositoryInterface
{
    /**
     * Internal document store keyed by string ID.
     *
     * @var array<string, array>
     */
    private array $store = [];

    /**
     * Find a single document by ID.
     *
     * {@inheritdoc}
     */
    public function findById(string $id): ?array
    {
        return $this->store[$id] ?? null;
    }

    /**
     * Find documents matching criteria.
     *
     * Filters by strict equality on all criteria keys.
     * Sorts using PHP's usort. Applies cursor as integer offset.
     *
     * {@inheritdoc}
     */
    public function findByCriteria(
        array $criteria,
        array $sort = [],
        int $limit = 50,
        ?string $cursor = null,
    ): array {
        $results = array_values(array_filter(
            $this->store,
            function (array $doc) use ($criteria): bool {
                foreach ($criteria as $key => $value) {
                    if (($doc[$key] ?? null) !== $value) {
                        return false;
                    }
                }
                return true;
            },
        ));

        if (!empty($sort)) {
            usort($results, function (array $a, array $b) use ($sort): int {
                foreach ($sort as $field => $direction) {
                    $cmp = $a[$field] ?? '' <=> $b[$field] ?? '';
                    if ($cmp !== 0) {
                        return $direction === 'desc' ? -$cmp : $cmp;
                    }
                }
                return 0;
            });
        }

        $offset = $cursor !== null ? (int) base64_decode($cursor) : 0;
        $results = array_slice($results, $offset);
        $results = array_slice($results, 0, $limit);

        return array_values($results);
    }

    /**
     * Find a paginated page of documents.
     *
     * Cursor is a base64-encoded integer offset into the filtered and sorted
     * result array. Fetches $limit + 1 to determine if more pages exist.
     *
     * {@inheritdoc}
     */
    public function findPage(
        array $criteria,
        int $limit,
        ?string $cursor = null,
        array $sort = [],
    ): PageResult {
        $offset = $cursor !== null ? (int) base64_decode($cursor) : 0;

        $allResults = array_values(array_filter(
            $this->store,
            function (array $doc) use ($criteria): bool {
                foreach ($criteria as $key => $value) {
                    if (($doc[$key] ?? null) !== $value) {
                        return false;
                    }
                }
                return true;
            },
        ));

        if (!empty($sort)) {
            usort($allResults, function (array $a, array $b) use ($sort): int {
                foreach ($sort as $field => $direction) {
                    $cmp = $a[$field] ?? '' <=> $b[$field] ?? '';
                    if ($cmp !== 0) {
                        return $direction === 'desc' ? -$cmp : $cmp;
                    }
                }
                return 0;
            });
        }

        $sliced = array_slice($allResults, $offset, $limit + 1);

        if (empty($sliced)) {
            return PageResult::empty();
        }

        $hasMore = count($sliced) > $limit;

        if ($hasMore) {
            $sliced = array_slice($sliced, 0, $limit);
        }

        $nextCursor = $hasMore ? base64_encode((string) ($offset + $limit)) : null;

        return new PageResult(
            items: array_values($sliced),
            nextCursor: $nextCursor,
            hasMore: $hasMore,
        );
    }

    /**
     * Count documents matching criteria.
     *
     * {@inheritdoc}
     */
    public function countByCriteria(array $criteria): int
    {
        return count(array_filter(
            $this->store,
            function (array $doc) use ($criteria): bool {
                foreach ($criteria as $key => $value) {
                    if (($doc[$key] ?? null) !== $value) {
                        return false;
                    }
                }
                return true;
            },
        ));
    }

    /**
     * Seed a document into the store.
     *
     * Use in test setUp() to prepare initial state.
     * The document must include an '_id' field if you intend to use findById().
     *
     * @param string $id       The document ID — used as the store key
     * @param array  $document The full document to store
     */
    public function seed(string $id, array $document): void
    {
        $this->store[$id] = $document;
    }

    /**
     * Reset the store to empty.
     *
     * Call in test tearDown() to ensure test isolation.
     */
    public function clear(): void
    {
        $this->store = [];
    }
}
