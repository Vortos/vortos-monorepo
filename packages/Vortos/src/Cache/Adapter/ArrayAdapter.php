<?php

declare(strict_types=1);

namespace Vortos\Cache\Adapter;

use Vortos\Cache\Contract\TaggedCacheInterface;

/**
 * Request-scoped in-process cache adapter.
 *
 * Stores values in a plain PHP array for the duration of a single request.
 * Unlike InMemoryAdapter (for tests) or RedisAdapter (for persistence across
 * requests), ArrayAdapter is explicitly designed for memoization within one
 * request — avoiding duplicate work (DB queries, permission checks, API calls)
 * that would otherwise repeat within the same request lifecycle.
 *
 * ## FrankenPHP worker mode requirement
 *
 * In FrankenPHP worker mode, workers stay alive across many requests.
 * Runner::cleanUp() MUST call ArrayAdapter::clear() after each request.
 * Without this, data from request N leaks into request N+1 in the same worker.
 * This is a correctness issue, not just a memory issue.
 *
 * ## TTL is ignored
 *
 * TTL parameters are accepted (PSR-16 compliance) but ignored.
 * All values expire when clear() is called (end of request).
 * There is no point tracking TTL for sub-request memoization.
 *
 * ## Real-world use cases in Squaura
 *
 *   - Cache authenticated user identity for the duration of one request
 *     (avoids re-fetching from DB on every permission check in the same request)
 *   - Cache permission evaluation results within a request
 *     (a controller may check the same permission 5 times — only compute once)
 *   - Cache tenant configuration lookup within a request
 */
final class ArrayAdapter implements TaggedCacheInterface
{
    /** @var array<string, mixed> */
    private array $store = [];

    /** @var array<string, string[]> tag → keys */
    private array $tags = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->store) ? $this->store[$key] : $default;
    }

    public function set(string $key, mixed $value, int|\DateInterval|null $ttl = null): bool
    {
        // TTL ignored — values live until clear() is called
        $this->store[$key] = $value;
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
            $this->store[$key] = $value;
        }
        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            unset($this->store[$key]);
        }
        return true;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->store);
    }

    public function setWithTags(string $key, mixed $value, array $tags, ?int $ttl = null): bool
    {
        $this->store[$key] = $value;

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
}
