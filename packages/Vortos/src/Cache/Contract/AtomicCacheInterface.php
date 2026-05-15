<?php

declare(strict_types=1);

namespace Vortos\Cache\Contract;

use Psr\SimpleCache\CacheInterface;

/**
 * Extends PSR-16 CacheInterface with an atomic set-if-not-exists operation.
 *
 * Required for correct idempotency guarantees in concurrent environments.
 * PSR-16's set() always overwrites; setNx() only writes when the key is absent,
 * eliminating the check-then-act (TOCTOU) race that exists with has() + set().
 */
interface AtomicCacheInterface extends CacheInterface
{
    /**
     * Store a value only if the key does not already exist (set-if-not-exists).
     *
     * Returns true  — key did not exist and was stored successfully.
     * Returns false — key already existed; value was NOT overwritten.
     *
     * The operation is atomic: no other process can observe a state between
     * the existence check and the write.
     *
     * @param int $ttl TTL in seconds — must be >= 1
     */
    public function setNx(string $key, mixed $value, int $ttl): bool;
}
