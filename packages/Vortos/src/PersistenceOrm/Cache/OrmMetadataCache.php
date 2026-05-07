<?php

declare(strict_types=1);

namespace Vortos\PersistenceOrm\Cache;

use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Vortos\Cache\Contract\TaggedCacheInterface;

/**
 * PSR-6 metadata cache pool for Doctrine ORM, backed by Vortos TaggedCacheInterface.
 *
 * Doctrine requires a PSR-6 CacheItemPoolInterface for metadata caching.
 * Vortos uses PSR-16 SimpleCache extended with tag-based invalidation.
 * This adapter bridges the two interfaces, storing all ORM metadata entries
 * tagged with 'orm_metadata' so they can be surgically cleared via
 * vortos:orm:clear-cache without affecting other cache entries.
 *
 * ## Key sanitization
 *
 * Doctrine generates metadata cache keys containing namespace separators (\),
 * colons, and other characters forbidden by PSR-16. sanitizeKey() replaces
 * all forbidden characters with underscores before storing in the underlying
 * cache. Doctrine only ever accesses its own keys — no collision risk.
 *
 * ## Deferred saves
 *
 * saveDeferred() queues items in memory. commit() flushes the queue.
 * This matches PSR-6 semantics and allows Doctrine to batch metadata writes.
 *
 * ## Production cache strategy
 *
 * In dev mode, Doctrine does not use this cache (isDevMode=true bypasses it).
 * In prod mode, metadata is read once on first boot, stored in Redis via this
 * adapter, and served from Redis on all subsequent requests — zero file I/O
 * or reflection per request.
 */
final class OrmMetadataCache implements CacheItemPoolInterface
{
    private const KEY_PREFIX  = 'orm_meta_';
    private const DEFAULT_TTL = 3600;
    private const TAG         = 'orm_metadata';

    /** @var array<string, OrmCacheItem> */
    private array $deferred = [];

    public function __construct(
        private readonly TaggedCacheInterface $cache,
        private readonly int $ttl = self::DEFAULT_TTL,
    ) {}

    public function getItem(string $key): OrmCacheItem
    {
        $value = $this->cache->get($this->prefixed($key));
        return new OrmCacheItem($key, $value !== null, $value);
    }

    /** @return iterable<string, OrmCacheItem> */
    public function getItems(array $keys = []): iterable
    {
        foreach ($keys as $key) {
            yield $key => $this->getItem($key);
        }
    }

    public function hasItem(string $key): bool
    {
        return $this->cache->has($this->prefixed($key));
    }

    public function clear(): bool
    {
        $this->deferred = [];
        return $this->cache->invalidateTags([self::TAG]);
    }

    public function deleteItem(string $key): bool
    {
        unset($this->deferred[$key]);
        return $this->cache->delete($this->prefixed($key));
    }

    public function deleteItems(array $keys): bool
    {
        $success = true;
        foreach ($keys as $key) {
            if (!$this->deleteItem($key)) {
                $success = false;
            }
        }
        return $success;
    }

    public function save(CacheItemInterface $item): bool
    {
        /** @var OrmCacheItem $item */
        return $this->cache->setWithTags(
            $this->prefixed($item->getKey()),
            $item->get(),
            [self::TAG],
            $item->getTtl($this->ttl),
        );
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        /** @var OrmCacheItem $item */
        $this->deferred[$item->getKey()] = $item;
        return true;
    }

    public function commit(): bool
    {
        $success = true;
        foreach ($this->deferred as $item) {
            if (!$this->save($item)) {
                $success = false;
            }
        }
        $this->deferred = [];
        return $success;
    }

    private function prefixed(string $key): string
    {
        return self::KEY_PREFIX . strtr($key, [
            '\\' => '_',
            '{'  => '_',
            '}'  => '_',
            '('  => '_',
            ')'  => '_',
            '/'  => '_',
            '@'  => '_',
            ':'  => '_',
        ]);
    }
}
