<?php

declare(strict_types=1);

namespace Vortos\Cache\Contract;

/**
 * Contract for cache warmers.
 *
 * Implement this interface on any service that pre-populates the cache
 * before traffic arrives. Tag the service with 'vortos.cache_warmer'
 * so CacheWarmupCommand discovers and runs it.
 *
 * ## Contract
 *
 * warmUp() must be idempotent — running it twice must produce the same result.
 * warmUp() must not throw for recoverable errors — log and continue.
 * warmUp() must complete in a reasonable time — it blocks the deploy pipeline.
 *
 * ## Real-world example for Squaura
 *
 *   final class PermissionMatrixCacheWarmer implements CacheWarmerInterface
 *   {
 *       public function __construct(
 *           private PermissionRepository $permissions,
 *           private TaggedCacheInterface $cache,
 *       ) {}
 *
 *       public function warmUp(): void
 *       {
 *           // Load all roles and permissions from DB once
 *           $matrix = $this->permissions->getFullMatrix();
 *
 *           // Store in cache tagged so they can be invalidated when roles change
 *           $this->cache->setWithTags(
 *               'auth:permission_matrix',
 *               $matrix,
 *               ['permissions', 'roles'],
 *               ttl: 3600,
 *           );
 *       }
 *   }
 *
 * After this runs, every permission check in the app hits Redis instead of
 * PostgreSQL — zero DB queries for authorization on every request.
 */
interface CacheWarmerInterface
{
    /**
     * Pre-populate the cache.
     *
     * Called by CacheWarmupCommand on deployment.
     * Must be idempotent — safe to run multiple times.
     *
     * @throws \Throwable If warmup fails unrecoverably — CacheWarmupCommand
     *                    catches and logs the error, then continues to next warmer
     */
    public function warmUp(): void;
}
