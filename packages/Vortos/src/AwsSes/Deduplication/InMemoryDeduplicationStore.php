<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Deduplication;

use Vortos\AwsSes\ValueObject\SentEmail;

/**
 * In-process deduplication store. State is lost on restart.
 *
 * Suitable for development, low-volume single-process deployments, and test suites.
 * For multi-process or multi-server setups, wire a Redis-backed implementation instead.
 *
 * Uses first-writer-wins semantics (consistent with RedisDeduplicationStore).
 * Performs lazy TTL pruning on every read and write to bound memory growth.
 */
final class InMemoryDeduplicationStore implements DeduplicationStoreInterface
{
    /** @var array<string, array{result: SentEmail, expiresAt: int}> */
    private array $store = [];

    public function findSent(string $key): ?SentEmail
    {
        $this->prune();
        return $this->store[$key]['result'] ?? null;
    }

    public function markSent(string $key, SentEmail $result, int $ttlSeconds = 86400): void
    {
        $this->prune();

        if (isset($this->store[$key])) {
            return; // first-writer-wins: do not overwrite an existing entry
        }

        $this->store[$key] = [
            'result'    => $result,
            'expiresAt' => time() + $ttlSeconds,
        ];
    }

    private function prune(): void
    {
        $now = time();
        foreach ($this->store as $k => $entry) {
            if ($entry['expiresAt'] <= $now) {
                unset($this->store[$k]);
            }
        }
    }
}
