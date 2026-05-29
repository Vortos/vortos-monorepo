<?php

declare(strict_types=1);

namespace Vortos\PersistenceMongo\Read;

use MongoDB\Client;
use MongoDB\Collection;
use MongoDB\Database;
use Psr\Log\LoggerInterface;
use Vortos\Domain\Repository\PageResult;
use Vortos\Metrics\Telemetry\FrameworkTelemetry;
use Vortos\Observability\Config\ObservabilityModule;
use Vortos\Observability\Telemetry\FrameworkMetric;
use Vortos\Observability\Telemetry\FrameworkMetricLabels;
use Vortos\Observability\Telemetry\MetricLabel;
use Vortos\Observability\Telemetry\MetricLabelValue;
use Vortos\Observability\Telemetry\MetricOperation;
use Vortos\Tracing\Config\TracingModule;
use Vortos\Tracing\Contract\TracingInterface;

/**
 * MongoDB-backed read store.
 *
 * Contains all persistence logic for MongoDB read repositories.
 * Injected into user repositories by MongoReadRepositoryAutowirePass when
 * the repository declares #[MongoCollection('collection_name')].
 *
 * ## Usage in your repository
 *
 *   #[MongoCollection('users')]
 *   #[MongoIndex(key: ['email' => 1], unique: true)]
 *   final class UserReadRepository implements UserReadRepositoryInterface
 *   {
 *       public function __construct(private readonly MongoStore $store) {}
 *
 *       public function findById(string $id): ?UserReadModel
 *       {
 *           $doc = $this->store->findById($id);
 *           return $doc !== null ? $this->fromDocument($doc) : null;
 *       }
 *
 *       private function fromDocument(array $doc): UserReadModel
 *       {
 *           return new UserReadModel(id: $doc['_id'], email: $doc['email'] ?? '');
 *       }
 *   }
 *
 * ## Return types
 *
 * All find methods return raw document arrays — mapping to read models is done
 * in the user repository's private fromDocument() method. findPage() returns
 * PageResult<array> — map items to typed models with array_map before returning.
 *
 * ## Keyset pagination
 *
 * findPage() uses keyset (cursor-based) pagination — not offset.
 * The cursor is opaque — pass it back verbatim to fetch the next page.
 *
 * ## Observability
 *
 * Tracing, metrics, and slow-query logging are injected at compile time via
 * MongoTracingCompilerPass, MongoMetricsCompilerPass, and MongoReadRepositoryAutowirePass.
 * Operations are wrapped in spans, metrics are recorded per operation, and queries
 * exceeding the slow query threshold are logged as warnings.
 *
 * ## Per-repository opt-out
 *
 * Annotate the repository class (not the store) with #[DisableTracing] or
 * #[DisableMetrics] to suppress injection for that specific repository.
 */
final class MongoStore
{
    private Database $database;
    private ?TracingInterface $tracer     = null;
    private ?FrameworkTelemetry $telemetry = null;
    private ?LoggerInterface $logger       = null;
    private int $slowQueryThresholdMs      = 100;

    public function __construct(Client $client, string $databaseName, string $collectionName)
    {
        $this->database       = $client->selectDatabase($databaseName);
        $this->collectionName = $collectionName;
    }

    private string $collectionName;

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

    private string $cursorSecret = '';

    /** @internal Injected by MongoMetricsCompilerPass at compile time */
    public function setMetrics(FrameworkTelemetry $telemetry): void
    {
        $this->telemetry = $telemetry;
    }

    /** @internal Injected by MongoReadRepositoryAutowirePass at compile time */
    public function setLogger(LoggerInterface $logger, int $slowQueryThresholdMs = 100): void
    {
        $this->logger              = $logger;
        $this->slowQueryThresholdMs = $slowQueryThresholdMs;
    }

    /**
     * Find a single document by _id. Returns raw array or null.
     *
     * @return array<string, mixed>|null
     */
    public function findById(string $id): ?array
    {
        return $this->traced('findOne', function () use ($id): ?array {
            $doc = $this->collection()->findOne(['_id' => $id]);
            return $doc !== null ? (array) $doc : null;
        });
    }

    /**
     * Find documents matching criteria. Returns list of raw arrays.
     *
     * @param array<string, mixed> $criteria
     * @param array<string, mixed> $sort
     * @return list<array<string, mixed>>
     */
    public function findByCriteria(
        array $criteria,
        array $sort = [],
        int $limit = 50,
        ?string $cursor = null,
    ): array {
        return $this->traced('find', function () use ($criteria, $sort, $limit, $cursor): array {
            return $this->fetchRaw($criteria, $sort, $limit, $cursor);
        });
    }

    /**
     * Paginated find. Returns PageResult with raw array items.
     * Map items to typed models in your repository:
     *
     *   $raw = $this->store->findPage($criteria, $limit, $cursor);
     *   return new PageResult(
     *       items: array_map(fn(array $doc) => $this->fromDocument($doc), $raw->items),
     *       nextCursor: $raw->nextCursor,
     *       hasMore: $raw->hasMore,
     *   );
     *
     * @param array<string, mixed> $criteria
     * @param array<string, mixed> $sort
     * @return PageResult<array<string, mixed>>
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

            $lastRaw    = end($rawDocs);
            $nextCursor = $hasMore ? $this->encodeCursor($lastRaw, $sort) : null;

            return new PageResult(items: $rawDocs, nextCursor: $nextCursor, hasMore: $hasMore);
        });
    }

    /**
     * Count documents matching criteria.
     *
     * @param array<string, mixed> $criteria
     */
    public function countByCriteria(array $criteria): int
    {
        return $this->traced('countDocuments', fn(): int => (int) $this->collection()->countDocuments($criteria));
    }

    /**
     * Insert or replace a single document by _id.
     *
     * @param array<string, mixed> $document Must contain '_id'
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
     * Returns the raw MongoDB Collection for custom queries (aggregation pipelines,
     * geospatial queries, text search, etc.).
     */
    public function collection(): Collection
    {
        return $this->database->selectCollection($this->collectionName);
    }

    /**
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
        if ($this->tracer === null && $this->telemetry === null && $this->logger === null) {
            return $fn();
        }

        $span  = $this->tracer?->startSpan('db.mongo.' . $operation, [
            'db.collection' => $this->collectionName,
            'db.operation'  => $operation,
            'db.system'     => 'mongodb',
            'vortos.module' => TracingModule::Persistence,
        ]);
        $start = hrtime(true);

        try {
            $result = $fn();
            $span?->setStatus('ok');
            return $result;
        } catch (\Throwable $e) {
            $span?->recordException($e);
            $span?->setStatus('error');
            throw $e;
        } finally {
            $span?->end();
            $durationMs = (hrtime(true) - $start) / 1_000_000;
            $this->recordMetrics($operation, $durationMs);
            $this->logIfSlow($operation, $durationMs);
        }
    }

    private function recordMetrics(string $operation, float $durationMs): void
    {
        if ($this->telemetry === null) {
            return;
        }

        $this->telemetry->increment(
            ObservabilityModule::Persistence,
            FrameworkMetric::DbQueriesTotal,
            FrameworkMetricLabels::of(
                MetricLabelValue::of(MetricLabel::Driver, 'mongodb'),
                MetricLabelValue::operation(MetricOperation::Query),
            ),
        );

        $this->telemetry->observe(
            ObservabilityModule::Persistence,
            FrameworkMetric::DbQueryDurationMs,
            FrameworkMetricLabels::of(MetricLabelValue::of(MetricLabel::Driver, 'mongodb')),
            $durationMs,
        );
    }

    private function logIfSlow(string $operation, float $durationMs): void
    {
        if ($this->logger === null || $durationMs < $this->slowQueryThresholdMs) {
            return;
        }

        $this->logger->warning('Slow MongoDB operation detected', [
            'collection'   => $this->collectionName,
            'operation'    => $operation,
            'duration_ms'  => round($durationMs, 2),
            'threshold_ms' => $this->slowQueryThresholdMs,
        ]);
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
            $op             = ($sort[$field] ?? 'asc') === 'desc' ? '$lt' : '$gt';
            $filter[$field] = [$op => $value];
        }

        return $filter;
    }
}
