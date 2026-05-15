<?php

declare(strict_types=1);

namespace Vortos\Cqrs\Command\Idempotency;

/**
 * In-memory command idempotency store for testing.
 *
 * Stores processed keys in a plain PHP array.
 * TTL is ignored — keys persist until clear() is called.
 *
 * ## Usage in tests
 *
 *   $store = new InMemoryCommandIdempotencyStore();
 *
 *   // Simulate duplicate command:
 *   $store->markProcessed('some-uuid');
 *   $this->assertTrue($store->wasProcessed('some-uuid'));
 *
 *   // Reset between tests:
 *   $store->clear();
 */
final class InMemoryCommandIdempotencyStore implements CommandIdempotencyStoreInterface
{
    /** @var array<string, true> */
    private array $store = [];

    /**
     * {@inheritdoc}
     */
    public function wasProcessed(string $idempotencyKey): bool
    {
        return isset($this->store[$idempotencyKey]);
    }

    /**
     * {@inheritdoc}
     *
     * TTL is ignored in the in-memory implementation.
     * Keys persist until clear() is called.
     */
    public function markProcessed(string $idempotencyKey, int $ttl = 86400): void
    {
        $this->store[$idempotencyKey] = true;
    }

    /**
     * {@inheritdoc}
     *
     * Atomic in a single-threaded PHP process.
     */
    public function tryMarkProcessed(string $idempotencyKey, int $ttl = 86400): bool
    {
        if (isset($this->store[$idempotencyKey])) {
            return false;
        }

        $this->store[$idempotencyKey] = true;

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function releaseProcessed(string $idempotencyKey): void
    {
        unset($this->store[$idempotencyKey]);
    }

    /**
     * Reset all stored keys.
     * Call in test tearDown() to ensure test isolation.
     */
    public function clear(): void
    {
        $this->store = [];
    }
}
