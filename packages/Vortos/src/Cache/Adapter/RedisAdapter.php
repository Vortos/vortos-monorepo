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
 * invalidateTags() fetches each tag's SET members in a pipeline, deletes all listed
 * keys and the tag SETs themselves in a second pipeline. Two round-trips total regardless
 * of how many tags or keys are involved.
 *
 * ## Tag SET TTL
 *
 * The tag SET TTL is always at least as long as the value TTL plus one hour, with a
 * minimum floor of 7 days. This prevents the tag SET from expiring before the values
 * it references, which would make those values un-invalidatable.
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
        /** @var list<class-string> */
        private array $allowedClasses = [],
    ) {}

    /**
     * Retrieve a cached value by key.
     *
     * Returns $default if the key does not exist, has expired, or if the stored
     * data is corrupted and cannot be unserialized.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $raw = $this->redis->get($this->prefixedKey($key));

        if ($raw === false) {
            return $default;
        }

        return $this->safeUnserialize($raw, $default);
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

        if ($seconds <= 0) {
            return false;
        }

        return (bool) $this->redis->setex($prefixed, $seconds, $serialized);
    }

    /**
     * Delete a single cache key.
     */
    public function delete(string $key): bool
    {
        $result = $this->redis->del($this->prefixedKey($key));
        return $result !== false;
    }

    /**
     * Delete all cache keys that match the configured prefix.
     *
     * Uses SCAN with cursor iteration — safe on large keyspaces, never blocks Redis.
     * Only keys with this adapter's prefix are affected.
     * Redis system keys, Kafka offsets, and messaging idempotency keys are untouched.
     * Returns false immediately if SCAN returns an error — does not loop indefinitely.
     */
    public function clear(): bool
    {
        $pattern = $this->prefix . '*';
        $cursor = null;

        do {
            $keys = $this->redis->scan($cursor, $pattern, 100);

            if ($keys === false) {
                return false;
            }

            if (!empty($keys)) {
                $this->redis->del(...$keys);
            }
        } while ($cursor !== 0 && $cursor !== null);

        return true;
    }

    /**
     * Retrieve multiple values by key.
     *
     * Uses a single mGet round-trip for all keys.
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
            $result[$key] = $raw !== false ? $this->safeUnserialize($raw, $default) : $default;
        }

        return $result;
    }

    /**
     * Store multiple key-value pairs.
     *
     * Uses a single pipeline round-trip for all keys — O(1) network cost regardless
     * of how many keys are written.
     *
     * @param iterable<string, mixed> $values
     */
    public function setMultiple(iterable $values, int|\DateInterval|null $ttl = null): bool
    {
        $seconds = $this->normalizeTtl($ttl);
        $valueArray = is_array($values) ? $values : iterator_to_array($values);

        if (empty($valueArray)) {
            return true;
        }

        if ($seconds <= 0) {
            return false;
        }

        $this->redis->multi(\Redis::PIPELINE);
        foreach ($valueArray as $key => $value) {
            $prefixed = $this->prefixedKey($key);
            $serialized = serialize($value);
            $this->redis->setex($prefixed, $seconds, $serialized);
        }
        $replies = $this->redis->exec();

        return !in_array(false, (array) $replies, true);
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
        $result = $this->redis->del(...$prefixed);

        return $result !== false;
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
     * Writes the value to Redis AND adds the prefixed key to each tag's SET inside
     * a single MULTI/EXEC transaction. Returns false if the transaction was aborted
     * or any command failed.
     *
     * Tag SET TTL is always at least max(valueTtl + 3600, 7 days) — the tag SET
     * will never expire before the value it indexes.
     */
    public function setWithTags(string $key, mixed $value, array $tags, ?int $ttl = null): bool
    {
        $seconds = $ttl ?? $this->defaultTtl;
        $prefixedKey = $this->prefixedKey($key);
        $tagTtl = max($seconds + 3600, 86400 * 7);

        $this->redis->multi();

        $this->redis->setex($prefixedKey, $seconds, serialize($value));

        foreach ($tags as $tag) {
            $tagKey = $this->tagKey($tag);
            $this->redis->sAdd($tagKey, $prefixedKey);
            $this->redis->expire($tagKey, $tagTtl);
        }

        $replies = $this->redis->exec();

        if ($replies === null || in_array(false, (array) $replies, true)) {
            return false;
        }

        return true;
    }

    /**
     * Invalidate all cache keys associated with any of the given tags.
     *
     * Two pipeline round-trips total regardless of tag/key count:
     * Phase 1 — pipeline all sMembers calls to fetch tag member sets.
     * Phase 2 — pipeline all DEL calls for value keys and tag keys.
     */
    public function invalidateTags(array $tags): bool
    {
        if (empty($tags)) {
            return true;
        }

        $tagKeys = array_map(fn(string $t) => $this->tagKey($t), $tags);

        // Phase 1: fetch all tag member sets in one pipeline round-trip
        $this->redis->multi(\Redis::PIPELINE);
        foreach ($tagKeys as $tagKey) {
            $this->redis->sMembers($tagKey);
        }
        $memberSets = $this->redis->exec();

        // Phase 2: delete all value keys and tag keys in one pipeline round-trip
        $this->redis->multi(\Redis::PIPELINE);
        foreach ((array) $memberSets as $members) {
            if (!empty($members)) {
                $this->redis->del(...$members);
            }
        }
        foreach ($tagKeys as $tagKey) {
            $this->redis->del($tagKey);
        }
        $this->redis->exec();

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
     * int  → use directly
     * DateInterval → convert to seconds, clamped to minimum 1 to prevent
     *                permanent storage when an expired interval is passed
     */
    private function normalizeTtl(int|\DateInterval|null $ttl): int
    {
        if ($ttl === null) {
            return $this->defaultTtl;
        }

        if ($ttl instanceof \DateInterval) {
            $seconds = (new \DateTimeImmutable())->add($ttl)->getTimestamp() - time();
            return max(0, $seconds);
        }

        return max(0, $ttl);
    }

    /**
     * Unserialize a raw Redis string, returning $default if the data is corrupted.
     *
     * `b:0;` is the serialized form of false — must not be treated as a failure.
     *
     * Object instantiation is restricted to $allowedClasses. When empty, only scalars
     * and arrays are permitted — objects trigger a deserialization failure and return
     * $default. Configure allowed classes via VortosCacheConfig::allowSerializedClasses().
     */
    private function safeUnserialize(string $raw, mixed $default): mixed
    {
        $options = ['allowed_classes' => $this->allowedClasses ?: false];
        $value = unserialize($raw, $options);

        if ($value === false && $raw !== 'b:0;') {
            return $default;
        }

        return $value;
    }
}
