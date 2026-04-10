<?php

declare(strict_types=1);

namespace Vortos\PersistenceMongo\Read;

use MongoDB\Client;
use MongoDB\Collection;
use MongoDB\Database;
use Vortos\Domain\Repository\PageResult;
use Vortos\Domain\Repository\ReadRepositoryInterface;

/**
 * Abstract MongoDB-backed read repository.
 *
 * Provides all standard read operations free.
 * You implement 3 methods describing your collection shape.
 *
 * ## Read side only
 *
 * This repository is for queries only — never for writes that need
 * transactional integrity. The read side is eventually consistent:
 * projections update read models asynchronously after domain events.
 *
 * ## Store _id as string UUID
 *
 * Always store document _id as a string UUID (UuidV7).
 * Never use MongoDB ObjectId for aggregates that have UuidV7 identities.
 * Mixing types causes silent type mismatch bugs when querying by ID.
 *
 * ## Implementing the 3 required methods
 *
 *   final class UserReadRepository extends MongoReadRepository
 *   {
 *       protected function collectionName(): string
 *       {
 *           return 'users';
 *       }
 *
 *       protected function fromDocument(array $doc): array
 *       {
 *           return [
 *               'id'    => $doc['_id'],
 *               'email' => $doc['email'],
 *               'name'  => $doc['name'],
 *           ];
 *       }
 *
 *       protected function indexes(): array
 *       {
 *           return [
 *               ['key' => ['email' => 1], 'options' => ['unique' => true]],
 *               ['key' => ['createdAt' => -1], 'options' => []],
 *           ];
 *       }
 *   }
 *
 * ## Keyset pagination
 *
 * findPage() uses keyset (cursor-based) pagination — not offset.
 * Offset pagination degrades exponentially as collections grow.
 * Keyset pagination is O(1) regardless of how deep you paginate.
 *
 * The cursor is an opaque base64-encoded string. Pass it back verbatim
 * to retrieve the next page. Do not parse or modify it.
 *
 * ## Index management
 *
 * Declare your indexes in indexes(). Run vortos:setup:persistence to
 * ensure they exist. createIndex() is idempotent — safe to run on every deploy.
 */
abstract class MongoReadRepository implements ReadRepositoryInterface
{
    private Database $database;

    public function __construct(Client $client, string $databaseName)
    {
        $this->database = $client->selectDatabase($databaseName);
    }

    /**
     * The MongoDB collection name for this repository.
     *
     * Example: 'users', 'order_read_models', 'competition_entries'
     */
    abstract protected function collectionName(): string;
    
    /**
     * Map a raw MongoDB document to a ViewModel or plain array.
     *
     * The '_id' field comes as a string if stored correctly.
     * Transform field names, cast types, and shape the output here.
     *
     * @param array $doc Raw MongoDB document
     * @return mixed     ViewModel object or plain array — your choice
     */
    abstract protected function fromDocument(array $doc): mixed;

    /**
     * Index definitions for this collection.
     *
     * Called by vortos:setup:persistence to ensure indexes exist.
     * Uses MongoDB's createIndex() format — idempotent, safe on every deploy.
     *
     * Return an empty array if no extra indexes are needed beyond _id.
     *
     * Format:
     *   [
     *       ['key' => ['email' => 1], 'options' => ['unique' => true]],
     *       ['key' => ['createdAt' => -1, '_id' => -1], 'options' => []],
     *   ]
     *
     * Key values: 1 = ascending, -1 = descending.
     * Options follow MongoDB createIndex() options exactly.
     *
     * @return array<int, array{key: array, options: array}>
     */
    abstract protected function indexes(): array;

    /**
     * Ensures all declared indexes exist on this collection.
     * Called by vortos:setup:persistence — idempotent, safe on every deploy.
     * Creates indexes that do not exist. Skips indexes that already exist.
     */
    public function ensureIndexes(): void
    {
        foreach ($this->indexes() as $indexDef) {
            $this->collection()->createIndex(
                $indexDef['key'],
                $indexDef['options'] ?? [],
            );
        }
    }

    /**
     * Returns the collection name for display in setup commands.
     * Delegates to the protected collectionName() method.
     */
    public function getCollectionName(): string
    {
        return $this->collectionName();
    }

    /**
     * Returns the number of declared indexes.
     * Used by setup commands to skip repositories with no index definitions.
     */
    public function getIndexCount(): int
    {
        return count($this->indexes());
    }

    /**
     * Find a single document by _id.
     *
     * Returns null if not found — never throws for missing documents.
     * Result is passed through fromDocument() before returning.
     *
     * {@inheritdoc}
     */
    public function findById(string $id): ?array
    {
        $doc = $this->collection()->findOne(['_id' => $id]);

        if ($doc === null) {
            return null;
        }

        return (array) $this->fromDocument((array) $doc);
    }

    /**
     * Find documents matching criteria.
     *
     * $criteria is a flat key-value filter — e.g. ['status' => 'active'].
     * $sort is ['field' => 'asc'|'desc'] — e.g. ['createdAt' => 'desc'].
     * $cursor enables keyset pagination — pass the cursor from the previous page.
     *
     * Returns raw arrays from fromDocument(). Mapping to ViewModels
     * is the caller's responsibility if objects are needed.
     *
     * {@inheritdoc}
     */
    public function findByCriteria(
        array $criteria,
        array $sort = [],
        int $limit = 50,
        ?string $cursor = null,
    ): array {
        if ($cursor !== null) {
            $cursorFilter = $this->decodeCursor($cursor);
            $criteria = array_merge($criteria, $cursorFilter);
        }

        $options = ['limit' => $limit];

        if (!empty($sort)) {
            $options['sort'] = array_map(
                fn(string $dir) => $dir === 'asc' ? 1 : -1,
                $sort,
            );
        }

        $cursor = $this->collection()->find($criteria, $options);

        return array_map(
            fn($doc) => (array) $this->fromDocument((array) $doc),
            iterator_to_array($cursor),
        );
    }

    /**
     * Find a paginated page of documents.
     *
     * Uses keyset pagination — O(1) performance regardless of page depth.
     * Fetches $limit + 1 documents to determine if more pages exist,
     * then slices back to $limit before returning.
     *
     * The returned PageResult contains:
     *   - items:      The documents for this page
     *   - nextCursor: Opaque string to pass to the next findPage() call
     *   - hasMore:    Whether more pages exist
     *
     * Pass nextCursor back verbatim — do not parse or modify it.
     *
     * {@inheritdoc}
     */
    public function findPage(
        array $criteria,
        int $limit,
        ?string $cursor = null,
        array $sort = [],
    ): PageResult {
        $items = $this->findByCriteria($criteria, $sort, $limit + 1, $cursor);

        if (empty($items)) {
            return PageResult::empty();
        }

        $hasMore = count($items) > $limit;

        if ($hasMore) {
            $items = array_slice($items, 0, $limit);
        }

        $lastItem = end($items);
        $nextCursor = $hasMore ? $this->encodeCursor($lastItem, $sort) : null;

        return new PageResult(
            items: $items,
            nextCursor: $nextCursor,
            hasMore: $hasMore,
        );
    }

    /**
     * Count documents matching criteria.
     *
     * WARNING: countDocuments() scans all matching documents.
     * On large collections without a supporting index this is expensive.
     * Prefer hasMore from PageResult for pagination UI — avoid count
     * unless you specifically need the total number.
     *
     * {@inheritdoc}
     */
    public function countByCriteria(array $criteria): int
    {
        return (int) $this->collection()->countDocuments($criteria);
    }

    /**
     * Insert or replace a single document by _id.
     *
     * Uses replaceOne with upsert: true — inserts if not exists, replaces if exists.
     * The document must contain an '_id' field matching the $id parameter.
     */
    public function upsert(string $id, array $document): void
    {
        $document['_id'] = $id;

        $this->collection()->replaceOne(
            ['_id' => $id],
            $document,
            ['upsert' => true],
        );
    }

    /**
     * Insert or replace multiple documents in a single bulk write operation.
     *
     * More efficient than calling upsert() in a loop.
     * Each document must contain an '_id' field.
     *
     * @param array<int, array> $documents Array of documents, each with '_id'
     */
    public function bulkUpsert(array $documents): void
    {
        if (empty($documents)) {
            return;
        }

        $operations = array_map(
            fn(array $doc) => [
                'replaceOne' => [
                    ['_id' => $doc['_id']],
                    $doc,
                    ['upsert' => true],
                ],
            ],
            $documents,
        );

        $this->collection()->bulkWrite($operations);
    }

    /**
     * Delete multiple documents by _id in a single operation.
     *
     * @param string[] $ids Array of string IDs to delete
     */
    public function bulkDelete(array $ids): void
    {
        if (empty($ids)) {
            return;
        }

        $this->collection()->deleteMany(['_id' => ['$in' => $ids]]);
    }

    /**
     * Exposes the MongoDB Collection for custom queries in subclasses.
     *
     * Use this for queries that findByCriteria() cannot express —
     * aggregation pipelines, text search, geospatial queries, etc.
     *
     * Example:
     *   $this->collection()->aggregate([
     *       ['$match' => ['status' => 'active']],
     *       ['$group' => ['_id' => '$category', 'count' => ['$sum' => 1]]],
     *   ]);
     */
    protected function collection(): Collection
    {
        return $this->database->selectCollection($this->collectionName());
    }

    /**
     * Encode the last item's sort field values into an opaque cursor string.
     *
     * The cursor is a base64-encoded JSON object containing the values
     * of all sort fields from the last item on the current page.
     * On the next page request, decodeCursor() converts this back into
     * a MongoDB filter ($gt or $lt depending on sort direction).
     *
     * The _id field is always included as a tiebreaker to ensure
     * deterministic pagination when sort field values are not unique.
     *
     * @param array $lastItem The last document on the current page
     * @param array $sort     Sort definition ['field' => 'asc'|'desc']
     */
    private function encodeCursor(array $lastItem, array $sort): string
    {
        $position = [];

        foreach (array_keys($sort) as $field) {
            $position[$field] = $lastItem[$field] ?? null;
        }

        $position['_id'] = $lastItem['_id'] ?? $lastItem['id'] ?? null;

        return base64_encode(json_encode($position, JSON_THROW_ON_ERROR));
    }

    /**
     * Decode a cursor string into MongoDB filter conditions.
     *
     * Converts the base64-encoded position back into a $gt or $lt filter
     * for each sort field. Ascending sort uses $gt (greater than last seen).
     * Descending sort uses $lt (less than last seen).
     *
     * @param  string $cursor Opaque cursor from encodeCursor()
     * @return array  MongoDB filter conditions to append to the query criteria
     */
    private function decodeCursor(string $cursor): array
    {
        $position = json_decode(base64_decode($cursor), true, 512, JSON_THROW_ON_ERROR);

        $filter = [];

        foreach ($position as $field => $value) {
            $filter[$field] = ['$gt' => $value];
        }

        return $filter;
    }
}
