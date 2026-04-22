<?php

declare(strict_types=1);

namespace Vortos\Cache\Adapter;

use Vortos\Cache\Contract\TaggedCacheInterface;

/**
 * In-memory cache adapter for testing.
 *
 * Stores values in a plain PHP array with lazy TTL expiry.
 * Lazy expiry means keys are not actively removed when they expire —
 * they are checked and discarded on the next get() or has() call.
 * This matches real cache adapter behavior from the caller's perspective.
 *
 * ## TTL behavior
 *
 * TTL is respected on read: an expired key returns $default from get()
 * and false from has(). The key is removed from the store on access.
 * This prevents the store from growing unboundedly in long-running tests.
 *
 * ## Usage in tests
 *
 *   $cache = new InMemoryAdapter();
 *   $cache->set('key', 'value', 60);
 *   $cache->has('key');  // true
 *   $cache->get('key');  // 'value'
 *   $cache->clear();     // reset between tests
 *
 * ## Never use in production
 *
 * Values are lost on process restart. No shared state between workers.
 * Use RedisAdapter in all non-test environments.
 */
final class InMemoryAdapter implements TaggedCacheInterface
{
    /**
     * @var array<string, array{value: mixed, expiresAt: int|null}>
     */
    private array $store = [];

    /**
     * @var array<string, string[]> tag → array of keys
     */
    private array $tags = [];

    public function get(string $key, mixed $default = null): mixed
    {
        if (!$this->has($key)) {
            return $default;
        }

        return $this->store[$key]['value'];
    }

    public function set(string $key, mixed $value, int|\DateInterval|null $ttl = null): bool
    {
        $this->store[$key] = [
            'value'     => $value,
            'expiresAt' => $this->resolveExpiresAt($ttl),
        ];

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->store[$key]);
        return true;
    }

    public function clear(): bool
    {
        $this->store = [];
        $this->tags = [];
        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }
        return $result;
    }

    public function setMultiple(iterable $values, int|\DateInterval|null $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $ttl);
        }
        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }
        return true;
    }

    /**
     * Check if a key exists and has not expired.
     * Removes the key from the store if it has expired (lazy expiry).
     */
    public function has(string $key): bool
    {
        if (!isset($this->store[$key])) {
            return false;
        }

        $entry = $this->store[$key];

        if ($entry['expiresAt'] !== null && $entry['expiresAt'] <= time()) {
            unset($this->store[$key]);
            return false;
        }

        return true;
    }

    public function setWithTags(string $key, mixed $value, array $tags, ?int $ttl = null): bool
    {
        $this->set($key, $value, $ttl);

        foreach ($tags as $tag) {
            $this->tags[$tag][] = $key;
        }

        return true;
    }

    public function invalidateTags(array $tags): bool
    {
        foreach ($tags as $tag) {
            if (!isset($this->tags[$tag])) {
                continue;
            }

            foreach ($this->tags[$tag] as $key) {
                unset($this->store[$key]);
            }

            unset($this->tags[$tag]);
        }

        return true;
    }

    /**
     * Resolve TTL to a Unix timestamp for expiry, or null for no expiry.
     */
    private function resolveExpiresAt(int|\DateInterval|null $ttl): ?int
    {
        if ($ttl === null) {
            return null;
        }

        if ($ttl instanceof \DateInterval) {
            return (new \DateTimeImmutable())->add($ttl)->getTimestamp();
        }

        return time() + $ttl;
    }
}
