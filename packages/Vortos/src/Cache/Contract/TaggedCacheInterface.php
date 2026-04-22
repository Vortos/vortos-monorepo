<?php

declare(strict_types=1);

namespace Vortos\Cache\Contract;

use Psr\SimpleCache\CacheInterface;

/**
 * Extends PSR-16 CacheInterface with tag-based invalidation.
 *
 * Tags allow grouping related cache entries so they can all be invalidated
 * together without knowing every individual key. This is the enterprise
 * standard for cache invalidation — used by Symfony Cache, Laravel Cache,
 * Doctrine Cache.
 *
 * ## Why tags matter at scale
 *
 * Without tags, invalidating "everything related to user 123" requires knowing
 * every key that was set for that user. At scale that is impossible — you have
 * no registry of what keys exist. Tags solve this: when you write any user:123
 * data, you tag it. When user 123 updates their profile, you call
 * invalidateTags(['user:123']) and every related key is gone atomically.
 *
 * ## Usage
 *
 *   // Store with tags:
 *   $cache->setWithTags('user:123:profile', $data, ['user:123', 'profiles'], 3600);
 *   $cache->setWithTags('user:123:permissions', $perms, ['user:123', 'permissions'], 3600);
 *
 *   // User updates profile — invalidate everything tagged user:123:
 *   $cache->invalidateTags(['user:123']);
 *   // Both keys are now gone. Other tags (profiles, permissions) are unaffected.
 *
 * ## PSR-16 not PSR-6
 *
 * PSR-16 (SimpleCache) uses simple get/set/delete with scalar keys and values.
 * PSR-6 (CacheItemPool) wraps everything in CacheItem objects — more overhead,
 * more boilerplate for zero benefit in 95% of use cases.
 * TaggedCacheInterface extends PSR-16 and adds tag methods on top.
 * PSR-6 is available via Symfony's bridge if a library requires it.
 */
interface TaggedCacheInterface extends CacheInterface
{
    /**
     * Store a value with associated tags.
     *
     * Writes the value to the cache AND updates the tag index for each tag,
     * mapping tag → set of keys. Both operations are atomic where possible.
     *
     * @param string   $key   Cache key (without prefix — prefix applied automatically)
     * @param mixed    $value Value to store (must be serializable)
     * @param string[] $tags  Tags to associate — e.g. ['user:123', 'profiles']
     * @param int|null $ttl   Seconds until expiry. Null uses the configured defaultTtl.
     *
     * @return bool True on success
     */
    public function setWithTags(string $key, mixed $value, array $tags, ?int $ttl = null): bool;

    /**
     * Invalidate all cache entries associated with any of the given tags.
     *
     * For each tag: fetches the set of keys tagged with it, deletes all those
     * keys, then deletes the tag index entry itself.
     *
     * Safe to call with tags that have no associated keys — returns true.
     *
     * @param string[] $tags Tags to invalidate — e.g. ['user:123']
     *
     * @return bool True on success
     */
    public function invalidateTags(array $tags): bool;
}
