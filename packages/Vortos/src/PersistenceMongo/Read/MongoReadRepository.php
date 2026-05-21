<?php

declare(strict_types=1);

namespace Vortos\PersistenceMongo\Read;

use MongoDB\Client;
use MongoDB\Collection;
use MongoDB\Database;
use Vortos\Domain\Repository\PageResult;
use Vortos\Domain\Repository\ReadRepositoryInterface;
use Vortos\Tracing\Config\TracingModule;
use Vortos\Tracing\Contract\TracingInterface;

/**
 * Abstract MongoDB-backed read repository.
 *
 * ## Declaring the collection
 *
 * Annotate your subclass with #[MongoCollection]:
 *
 *   #[MongoCollection('users')]
 *   final class UserReadRepository extends MongoReadRepository { ... }
 *
 * ## Declaring indexes
 *
 * Use the repeatable #[MongoIndex] attribute on the same class:
 *
 *   #[MongoCollection('users')]
 *   #[MongoIndex(key: ['email' => 1], unique: true)]
 *   #[MongoIndex(key: ['createdAt' => -1, '_id' => -1])]
 *   final class UserReadRepository extends MongoReadRepository { ... }
 *
 * Apply them with: php bin/console vortos:mongo:sync
 *
 * ## Typed read models
 *
 * fromDocument() can return any type — a plain array or a typed read model DTO:
 *
 *   protected function fromDocument(array $doc): UserReadModel
 *   {
 *       return new UserReadModel(id: $doc['_id'], email: $doc['email']);
 *   }
 *
 * findById() and findByCriteria() return whatever fromDocument() returns.
 * Use @extends MongoReadRepository<UserReadModel> on your subclass for IDE generics.
 *
 * ## Keyset pagination
 *
 * findPage() uses keyset (cursor-based) pagination — not offset.
 * The cursor is opaque — pass it back verbatim to fetch the next page.
 *
 * @template T
 * @implements ReadRepositoryInterface<T>
 */
abstract class MongoReadRepository implements ReadRepositoryInterface
{
    private Database $database;
    private string $collectionName;
    private ?TracingInterface $tracer = null;
    private string $cursorSecret = '';

    public function __construct(Client $client, string $databaseName, string $collectionName)
    {
        $this->database       = $client->selectDatabase($databaseName);
        $this->collectionName = $collectionName;
    }

    /** @internal Injected by MongoTracingCompilerPass at compile time */
    public function setTracer(TracingInterface $tracer): void
    {
        $this->tracer = $tracer;
    }

    /** @internal Injected by MongoCursorSecretCompilerPass at compile time */
    public function setCursorSecret(string $secret): void
    {
        $this->cursorSecret = $secret;
    }

    /**
     * Map a raw MongoDB document to your read model.
     *
     * Return a typed DTO or a plain array — your choice.
     * The return value is passed through as-is from findById() and findByCriteria().
     *
     * @param array<string, mixed> $doc Raw BSON document cast to array
     * @return T
     */
    abstract protected function fromDocument(array $doc): mixed;

    /**
     * @return T|null
     * {@inheritdoc}
     */
    public function findById(string $id): mixed
    {
        return $this->traced('findOne', function () use ($id): mixed {
            $doc = $this->collection()->findOne(['_id' => $id]);

            if ($doc === null) {
                return null;
            }

            return $this->fromDocument((array) $doc);
        });
    }

    /**
     * @return list<T>
     * {@inheritdoc}
     */
    public function findByCriteria(
        array $criteria,
        array $sort = [],
        int $limit = 50,
        ?string $cursor = null,
    ): array {
        return $this->traced('find', function () use ($criteria, $sort, $limit, $cursor): array {
            $rawDocs = $this->fetchRaw($criteria, $sort, $limit, $cursor);

            return array_map(fn(array $doc) => $this->fromDocument($doc), $rawDocs);
        });
    }

    /**
     * @return PageResult<T>
     * {@inheritdoc}
     */
    public function findPage(
        array $criteria,
        int $limit,
        ?string $cursor = null,
        array $sort = [],
    ): PageResult {
        return $this->traced('find', function () use ($criteria, $limit, $cursor, $sort): PageResult {
            $rawDocs = $this->fetchRaw($criteria, $sort, $limit + 1, $cursor);

            if (empty($rawDocs)) {
                return PageResult::empty();
            }

            $hasMore = count($rawDocs) > $limit;

            if ($hasMore) {
                $rawDocs = array_slice($rawDocs, 0, $limit);
            }

            $items      = array_map(fn(array $doc) => $this->fromDocument($doc), $rawDocs);
            $lastRaw    = end($rawDocs);
            $nextCursor = $hasMore ? $this->encodeCursor($lastRaw, $sort) : null;

            return new PageResult(items: $items, nextCursor: $nextCursor, hasMore: $hasMore);
        });
    }

    /**
     * {@inheritdoc}
     */
    public function countByCriteria(array $criteria): int
    {
        return $this->traced('countDocuments', fn(): int => (int) $this->collection()->countDocuments($criteria));
    }

    /**
     * Insert or replace a single document by _id.
     */
    public function upsert(string $id, array $document): void
    {
        $this->traced('replaceOne', function () use ($id, $document): null {
            $document['_id'] = $id;

            $this->collection()->replaceOne(
                ['_id' => $id],
                $document,
                ['upsert' => true],
            );

            return null;
        });
    }

    /**
     * Insert or replace multiple documents in a single bulk write.
     *
     * @param array<int, array<string, mixed>> $documents Each must contain '_id'
     */
    public function bulkUpsert(array $documents): void
    {
        if (empty($documents)) {
            return;
        }

        $this->traced('bulkWrite', function () use ($documents): null {
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

            return null;
        });
    }

    /**
     * Delete multiple documents by _id in a single operation.
     *
     * @param string[] $ids
     */
    public function bulkDelete(array $ids): void
    {
        if (empty($ids)) {
            return;
        }

        $this->traced('deleteMany', function () use ($ids): null {
            $this->collection()->deleteMany(['_id' => ['$in' => $ids]]);
            return null;
        });
    }

    /**
     * Exposes the raw MongoDB Collection for custom queries in subclasses.
     * Use for aggregation pipelines, geospatial queries, text search, etc.
     */
    protected function collection(): Collection
    {
        return $this->database->selectCollection($this->collectionName);
    }

    /**
     * Fetch raw BSON documents as arrays. Used internally by findByCriteria and findPage.
     *
     * @return list<array<string, mixed>>
     */
    private function fetchRaw(array $criteria, array $sort, int $limit, ?string $cursor = null): array
    {
        if ($cursor !== null) {
            $cursorFilter = $this->decodeCursor($cursor, $sort);
            $criteria     = array_merge($criteria, $cursorFilter);
        }

        $options = ['limit' => $limit];

        if (!empty($sort)) {
            $options['sort'] = array_map(
                fn(string $dir) => $dir === 'asc' ? 1 : -1,
                $sort,
            );
        }

        return array_map(
            fn($doc) => (array) $doc,
            iterator_to_array($this->collection()->find($criteria, $options)),
        );
    }

    private function traced(string $operation, callable $fn): mixed
    {
        if ($this->tracer === null) {
            return $fn();
        }

        $span = $this->tracer->startSpan('db.mongo.' . $operation, [
            'db.collection' => $this->collectionName,
            'db.operation'  => $operation,
            'db.system'     => 'mongodb',
            'vortos.module' => TracingModule::Persistence,
        ]);

        try {
            $result = $fn();
            $span->setStatus('ok');
            return $result;
        } catch (\Throwable $e) {
            $span->recordException($e);
            $span->setStatus('error');
            throw $e;
        } finally {
            $span->end();
        }
    }

    private function encodeCursor(array $lastRaw, array $sort): string
    {
        $position = [];

        foreach (array_keys($sort) as $field) {
            $position[$field] = $lastRaw[$field] ?? null;
        }

        $position['_id'] = $lastRaw['_id'] ?? null;

        $encoded = base64_encode(json_encode($position, JSON_THROW_ON_ERROR));

        if ($this->cursorSecret !== '') {
            $encoded .= '.' . hash_hmac('sha256', $encoded, $this->cursorSecret);
        }

        return $encoded;
    }

    private function decodeCursor(string $cursor, array $sort): array
    {
        $encoded = $cursor;

        if ($this->cursorSecret !== '') {
            $dotPos = strrpos($cursor, '.');
            if ($dotPos === false) {
                throw new \InvalidArgumentException('Invalid pagination cursor.');
            }
            $encoded = substr($cursor, 0, $dotPos);
            $sig     = substr($cursor, $dotPos + 1);
            if (!hash_equals(hash_hmac('sha256', $encoded, $this->cursorSecret), $sig)) {
                throw new \InvalidArgumentException('Invalid pagination cursor.');
            }
        }

        $position = json_decode(base64_decode($encoded), true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($position)) {
            throw new \InvalidArgumentException('Invalid pagination cursor.');
        }

        $allowedFields = array_fill_keys(array_keys($sort), true) + ['_id' => true];
        $filter        = [];

        foreach ($allowedFields as $field => $_) {
            if (!array_key_exists($field, $position)) {
                continue;
            }
            $value = $position[$field];
            if (!is_scalar($value) && $value !== null) {
                throw new \InvalidArgumentException('Invalid pagination cursor.');
            }
            $op            = ($sort[$field] ?? 'asc') === 'desc' ? '$lt' : '$gt';
            $filter[$field] = [$op => $value];
        }

        return $filter;
    }
}
