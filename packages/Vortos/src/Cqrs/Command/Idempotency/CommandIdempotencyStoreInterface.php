<?php

declare(strict_types=1);

namespace Vortos\Cqrs\Command\Idempotency;

/**
 * Contract for command idempotency stores.
 *
 * Prevents the same command from being processed more than once.
 * Every command carries an idempotencyKey() — a client-generated UUID
 * that uniquely identifies this specific command execution.
 *
 * ## Why idempotency matters
 *
 * HTTP clients retry requests on network errors. Mobile apps retry on timeout.
 * Message queues deliver at-least-once. Without idempotency, a user who clicks
 * "Register" twice (or whose browser retries) creates two accounts.
 *
 * ## Key TTL
 *
 * Keys are stored with a TTL (default 86400 seconds = 24 hours).
 * After the TTL, the same idempotency key can be reused.
 * This is acceptable — a command from 25 hours ago is not a duplicate.
 *
 * ## Implementations
 *
 * RedisCommandIdempotencyStore  — default, fast, TTL-based, uses CacheInterface
 * DbalCommandIdempotencyStore   — durable, queryable, good for audit requirements
 * InMemoryCommandIdempotencyStore — testing only, resets with clear()
 *
 * ## Swapping implementations
 *
 * In config/cqrs.php:
 *   $config->commandBus()->idempotencyStore(DbalCommandIdempotencyStore::class);
 */
interface CommandIdempotencyStoreInterface
{
    /**
     * Check if a command with this idempotency key was already processed.
     *
     * @param string $idempotencyKey Client-generated UUID from CommandInterface::idempotencyKey()
     * @return bool True if already processed — command should be skipped
     */
    public function wasProcessed(string $idempotencyKey): bool;

    /**
     * Mark a command as successfully processed.
     *
     * Called after successful handler execution and commit.
     * Stores the key with a TTL so it expires after the window.
     *
     * @param string $idempotencyKey Client-generated UUID
     * @param int    $ttl            Seconds until key expires (default: 86400 = 24 hours)
     */
    public function markProcessed(string $idempotencyKey, int $ttl = 86400): void;

    /**
     * Atomically claim an idempotency key.
     *
     * Combines wasProcessed() and markProcessed() into a single atomic operation,
     * eliminating the TOCTOU race between the check and the write.
     *
     * Returns true  — key was not yet claimed; this call claimed it successfully.
     * Returns false — key was already claimed by a prior call; command should be skipped.
     *
     * If the command handler subsequently fails (exception or transaction rollback),
     * call releaseProcessed() to remove the claim so the command can be retried.
     *
     * @param string $idempotencyKey Client-generated UUID
     * @param int    $ttl            Seconds until key expires
     */
    public function tryMarkProcessed(string $idempotencyKey, int $ttl = 86400): bool;

    /**
     * Release a previously claimed idempotency key.
     *
     * Call this when the command handler fails after tryMarkProcessed() returned true,
     * so the command can be safely retried with the same idempotency key.
     *
     * @param string $idempotencyKey The key to release
     */
    public function releaseProcessed(string $idempotencyKey): void;
}
