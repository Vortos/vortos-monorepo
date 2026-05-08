<?php

declare(strict_types=1);

namespace Vortos\Cache\Tracing;

use Psr\SimpleCache\CacheInterface;
use Vortos\Cache\Contract\TaggedCacheInterface;
use Vortos\Tracing\Config\TracingModule;
use Vortos\Tracing\Contract\TracingInterface;

/**
 * Wraps the active TaggedCacheInterface to add per-operation tracing spans.
 *
 * Registered by CacheExtension when TracingInterface is available.
 * Both CacheInterface and TaggedCacheInterface aliases point to this decorator
 * instead of the raw adapter — all injections get tracing automatically.
 *
 * Respects VortosTracingConfig::disable(TracingModule::Cache) — when disabled,
 * ModuleAwareTracer returns NoOpSpan so this decorator becomes near-zero overhead.
 *
 * ## Span names
 *
 *   cache.get    — get() / getMultiple()
 *   cache.set    — set() / setMultiple() / setWithTags()
 *   cache.delete — delete() / deleteMultiple() / invalidateTags()
 *   cache.has    — has()
 *   cache.clear  — clear()
 */
final class TracingCacheAdapter implements TaggedCacheInterface
{
    private static ?object $sentinel = null;

    public function __construct(
        private readonly TaggedCacheInterface $inner,
        private readonly TracingInterface $tracer,
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $sentinel = self::$sentinel ??= new \stdClass();

        $span = $this->tracer->startSpan('cache.get', [
            'cache.key'     => $key,
            'vortos.module' => TracingModule::Cache,
        ]);

        try {
            $value = $this->inner->get($key, $sentinel);
            $hit = $value !== $sentinel;
            $span->addAttribute('cache.hit', $hit);
            $span->setStatus('ok');
            return $hit ? $value : $default;
        } catch (\Throwable $e) {
            $span->recordException($e);
            $span->setStatus('error');
            throw $e;
        } finally {
            $span->end();
        }
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $span = $this->tracer->startSpan('cache.set', [
            'cache.key'     => $key,
            'vortos.module' => TracingModule::Cache,
        ]);

        try {
            $result = $this->inner->set($key, $value, $ttl);
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

    public function delete(string $key): bool
    {
        $span = $this->tracer->startSpan('cache.delete', [
            'cache.key'     => $key,
            'vortos.module' => TracingModule::Cache,
        ]);

        try {
            $result = $this->inner->delete($key);
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

    public function clear(): bool
    {
        $span = $this->tracer->startSpan('cache.clear', [
            'vortos.module' => TracingModule::Cache,
        ]);

        try {
            $result = $this->inner->clear();
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

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $keyList = is_array($keys) ? $keys : iterator_to_array($keys);
        $span = $this->tracer->startSpan('cache.get', [
            'cache.key_count' => count($keyList),
            'cache.multiple'  => true,
            'vortos.module'   => TracingModule::Cache,
        ]);

        try {
            $result = $this->inner->getMultiple($keyList, $default);
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

    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        $valueArray = is_array($values) ? $values : iterator_to_array($values);
        $span = $this->tracer->startSpan('cache.set', [
            'cache.key_count' => count($valueArray),
            'cache.multiple'  => true,
            'vortos.module'   => TracingModule::Cache,
        ]);

        try {
            $result = $this->inner->setMultiple($valueArray, $ttl);
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

    public function deleteMultiple(iterable $keys): bool
    {
        $keyList = is_array($keys) ? $keys : iterator_to_array($keys);
        $span = $this->tracer->startSpan('cache.delete', [
            'cache.key_count' => count($keyList),
            'cache.multiple'  => true,
            'vortos.module'   => TracingModule::Cache,
        ]);

        try {
            $result = $this->inner->deleteMultiple($keyList);
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

    public function has(string $key): bool
    {
        $span = $this->tracer->startSpan('cache.has', [
            'cache.key'     => $key,
            'vortos.module' => TracingModule::Cache,
        ]);

        try {
            $result = $this->inner->has($key);
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

    public function setWithTags(string $key, mixed $value, array $tags, ?int $ttl = null): bool
    {
        $span = $this->tracer->startSpan('cache.set', [
            'cache.key'     => $key,
            'cache.tags'    => implode(',', $tags),
            'vortos.module' => TracingModule::Cache,
        ]);

        try {
            $result = $this->inner->setWithTags($key, $value, $tags, $ttl);
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

    public function invalidateTags(array $tags): bool
    {
        $span = $this->tracer->startSpan('cache.delete', [
            'cache.tags'    => implode(',', $tags),
            'cache.by_tags' => true,
            'vortos.module' => TracingModule::Cache,
        ]);

        try {
            $result = $this->inner->invalidateTags($tags);
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
}
