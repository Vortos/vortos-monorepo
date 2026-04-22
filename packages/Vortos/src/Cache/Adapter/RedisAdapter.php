<?php

declare(strict_types=1);

namespace Vortos\Cache\Adapter;

use Vortos\Cache\Contract\TaggedCacheInterface;

/**
 * Redis-backed cache adapter.
 *
 * Implements TaggedCacheInterface using ext-redis directly.
 * All values are serialized with PHP's native serialize()/unserialize()
 * which handles all PHP types including objects and arrays.
 *
 * ## Key format
 *
 *   {prefix}{key}                 — the cached value
 *   {prefix}__tag__{tagName}      — a Redis SET containing all prefixed keys tagged with tagName
 *
 * ## Tag invalidation
 *
 * setWithTags() stores the value AND adds the prefixed key to each tag's SET.
 * invalidateTags() fetches each tag's SET, deletes all listed keys, then deletes
 * the tag SET itself. All Redis operations use the native ext-redis pipeline
 * where possible for performance.
 *
 * ## Key prefix
 *
 * Always configure a prefix that includes APP_ENV and app name.
 * Format: {env}_{appName}_ — e.g. 'dev_squaura_', 'prod_squaura_'
 * This prevents key collisions between environments on a shared Redis instance.
 *
 * ## Clearing cache
 *
 * clear() uses SCAN with cursor iteration — never FLUSHDB.
 * FLUSHDB wipes the entire Redis database including Kafka consumer group offsets,
 * messaging idempotency keys, and session data. SCAN+DEL with prefix is safe.
 */
final class RedisAdapter implements TaggedCacheInterface
{
    private const TAG_PREFIX = '__tag__';

    public function __construct(
        private \Redis $redis,
        private string $prefix = '',
        private int $defaultTtl = 3600,
    ) {}

    /**
     * Retrieve a cached value by key.
     *
     * Returns $default if the key does not exist or has expired.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $raw = $this->redis->get($this->prefixedKey($key));

        if ($raw === false) {
            return $default;
        }

        return unserialize($raw);
    }

    /**
     * Store a value in the cache.
     *
     * @param string                 $key   Cache key
     * @param mixed                  $value Value — must be serializable
     * @param int|\DateInterval|null $ttl   TTL in seconds, DateInterval, or null for default
     */
    public function set(string $key, mixed $value, int|\DateInterval|null $ttl = null): bool
    {
        $seconds = $this->normalizeTtl($ttl);
        $prefixed = $this->prefixedKey($key);
        $serialized = serialize($value);

        if ($seconds > 0) {
            return (bool) $this->redis->setex($prefixed, $seconds, $serialized);
        }

        return (bool) $this->redis->set($prefixed, $serialized);
    }

    /**
     * Delete a single cache key.
     */
    public function delete(string $key): bool
    {
        return $this->redis->del($this->prefixedKey($key)) >= 0;
    }

    /**
     * Delete all cache keys that match the configured prefix.
     *
     * Uses SCAN with cursor iteration — safe on large keyspaces, never blocks Redis.
     * Only keys with this adapter's prefix are affected.
     * Redis system keys, Kafka offsets, and messaging idempotency keys are untouched.
     */
    public function clear(): bool
    {
        $pattern = $this->prefix . '*';
        $cursor = null;

        do {
            $keys = $this->redis->scan($cursor, $pattern, 100);

            if (!empty($keys)) {
                $this->redis->del(...$keys);
            }
        } while ($cursor !== 0 && $cursor !== null);

        return true;
    }

    /**
     * Retrieve multiple values by key.
     *
     * @param iterable<string> $keys
     * @return iterable<string, mixed>
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $keyArray = is_array($keys) ? $keys : iterator_to_array($keys);
        $prefixed = array_map(fn(string $k) => $this->prefixedKey($k), $keyArray);
        $values = $this->redis->mGet($prefixed);

        $result = [];
        foreach ($keyArray as $i => $key) {
            $raw = $values[$i] ?? false;
            $result[$key] = $raw !== false ? unserialize($raw) : $default;
        }

        return $result;
    }

    /**
     * Store multiple key-value pairs.
     *
     * @param iterable<string, mixed> $values
     */
    public function setMultiple(iterable $values, int|\DateInterval|null $ttl = null): bool
    {
        $seconds = $this->normalizeTtl($ttl);
        $success = true;

        foreach ($values as $key => $value) {
            $result = $this->set($key, $value, $seconds);
            if (!$result) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Delete multiple keys.
     *
     * @param iterable<string> $keys
     */
    public function deleteMultiple(iterable $keys): bool
    {
        $keyArray = is_array($keys) ? $keys : iterator_to_array($keys);

        if (empty($keyArray)) {
            return true;
        }

        $prefixed = array_map(fn(string $k) => $this->prefixedKey($k), $keyArray);

        return $this->redis->del(...$prefixed) >= 0;
    }

    /**
     * Check if a key exists and has not expired.
     */
    public function has(string $key): bool
    {
        return (bool) $this->redis->exists($this->prefixedKey($key));
    }

    /**
     * Store a value with associated tags.
     *
     * Writes the value to Redis AND adds the prefixed key to each tag's SET.
     * The tag SET TTL is set to 7 days — generous enough to outlive most values.
     */
    public function setWithTags(string $key, mixed $value, array $tags, ?int $ttl = null): bool
    {
        $seconds = $ttl ?? $this->defaultTtl;
        $prefixedKey = $this->prefixedKey($key);

        $this->redis->multi();

        $this->redis->setex($prefixedKey, $seconds, serialize($value));

        foreach ($tags as $tag) {
            $tagKey = $this->tagKey($tag);
            $this->redis->sAdd($tagKey, $prefixedKey);
            $this->redis->expire($tagKey, 86400 * 7); // 7 days
        }

        $this->redis->exec();

        return true;
    }

    /**
     * Invalidate all cache keys associated with any of the given tags.
     *
     * For each tag: fetches all keys in the tag's SET, deletes them,
     * then deletes the tag SET itself.
     */
    public function invalidateTags(array $tags): bool
    {
        foreach ($tags as $tag) {
            $tagKey = $this->tagKey($tag);
            $keys = $this->redis->sMembers($tagKey);

            if (!empty($keys)) {
                $this->redis->del(...$keys);
            }

            $this->redis->del($tagKey);
        }

        return true;
    }

    /**
     * Apply the configured prefix to a key.
     */
    private function prefixedKey(string $key): string
    {
        return $this->prefix . $key;
    }

    /**
     * Build the Redis key for a tag's SET index.
     */
    private function tagKey(string $tag): string
    {
        return $this->prefix . self::TAG_PREFIX . $tag;
    }

    /**
     * Normalize TTL to integer seconds.
     *
     * null → defaultTtl
     * int → use directly
     * DateInterval → convert to seconds
     */
    private function normalizeTtl(int|\DateInterval|null $ttl): int
    {
        if ($ttl === null) {
            return $this->defaultTtl;
        }

        if ($ttl instanceof \DateInterval) {
            return (new \DateTimeImmutable())->add($ttl)->getTimestamp() - time();
        }

        return $ttl;
    }
}
