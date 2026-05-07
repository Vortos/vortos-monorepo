<?php

declare(strict_types=1);

namespace Vortos\Metrics\AutoInstrumentation;

use Vortos\Cache\Contract\TaggedCacheInterface;
use Vortos\Metrics\Contract\MetricsInterface;

/**
 * Decorates TaggedCacheInterface to record per-operation cache metrics.
 *
 * ## Metrics recorded
 *
 *   vortos_cache_operations_total{operation, result}  — counter
 *     operation: get | set | delete | has | clear
 *     result:    hit | miss | ok (only get distinguishes hit/miss)
 *
 * ## Lazy iteration
 *
 *   getMultiple() yields results one by one — the inner adapter's generator is
 *   never materialized into a full array. setMultiple() and deleteMultiple()
 *   use a counting wrapper generator so the inner adapter receives an iterable
 *   and the count is known without loading everything into memory.
 */
final class CacheMetricsDecorator implements TaggedCacheInterface
{
    public function __construct(
        private readonly TaggedCacheInterface $inner,
        private readonly MetricsInterface $metrics,
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->inner->get($key, $default);
        $this->metrics->counter('cache_operations_total', [
            'operation' => 'get',
            'result'    => $value !== $default ? 'hit' : 'miss',
        ])->increment();
        return $value;
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $result = $this->inner->set($key, $value, $ttl);
        $this->metrics->counter('cache_operations_total', ['operation' => 'set', 'result' => 'ok'])->increment();
        return $result;
    }

    public function delete(string $key): bool
    {
        $result = $this->inner->delete($key);
        $this->metrics->counter('cache_operations_total', ['operation' => 'delete', 'result' => 'ok'])->increment();
        return $result;
    }

    public function clear(): bool
    {
        $result = $this->inner->clear();
        $this->metrics->counter('cache_operations_total', ['operation' => 'clear', 'result' => 'ok'])->increment();
        return $result;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        foreach ($this->inner->getMultiple($keys, $default) as $key => $value) {
            $this->metrics->counter('cache_operations_total', [
                'operation' => 'get',
                'result'    => $value !== $default ? 'hit' : 'miss',
            ])->increment();
            yield $key => $value;
        }
    }

    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        $count   = 0;
        $counted = (static function () use ($values, &$count): \Generator {
            foreach ($values as $key => $value) {
                ++$count;
                yield $key => $value;
            }
        })();

        $result = $this->inner->setMultiple($counted, $ttl);

        if ($count > 0) {
            $this->metrics->counter('cache_operations_total', ['operation' => 'set', 'result' => 'ok'])->increment($count);
        }

        return $result;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        $count   = 0;
        $counted = (static function () use ($keys, &$count): \Generator {
            foreach ($keys as $key) {
                ++$count;
                yield $key;
            }
        })();

        $result = $this->inner->deleteMultiple($counted);

        if ($count > 0) {
            $this->metrics->counter('cache_operations_total', ['operation' => 'delete', 'result' => 'ok'])->increment($count);
        }

        return $result;
    }

    public function has(string $key): bool
    {
        $result = $this->inner->has($key);
        $this->metrics->counter('cache_operations_total', ['operation' => 'has', 'result' => $result ? 'hit' : 'miss'])->increment();
        return $result;
    }

    public function setWithTags(string $key, mixed $value, array $tags, ?int $ttl = null): bool
    {
        $result = $this->inner->setWithTags($key, $value, $tags, $ttl);
        $this->metrics->counter('cache_operations_total', ['operation' => 'set', 'result' => 'ok'])->increment();
        return $result;
    }

    public function invalidateTags(array $tags): bool
    {
        $result = $this->inner->invalidateTags($tags);
        $this->metrics->counter('cache_operations_total', ['operation' => 'delete', 'result' => 'ok'])->increment();
        return $result;
    }
}
