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
 * Performs lazy TTL pruning on every read to avoid unbounded memory growth.
 */
final class InMemoryDeduplicationStore implements DeduplicationStoreInterface
{
    /** @var array<string, array{result: SentEmail, expiresAt: int}> */
    private array $store = [];

    public function isDuplicate(string $key): bool
    {
        $this->prune();
        return isset($this->store[$key]);
    }

    public function markSent(string $key, SentEmail $result, int $ttlSeconds = 86400): void
    {
        $this->store[$key] = [
            'result'    => $result,
            'expiresAt' => time() + $ttlSeconds,
        ];
    }

    public function getSent(string $key): ?SentEmail
    {
        $this->prune();
        return $this->store[$key]['result'] ?? null;
    }

    private function prune(): void
    {
        $now = time();
        foreach ($this->store as $key => $entry) {
            if ($entry['expiresAt'] <= $now) {
                unset($this->store[$key]);
            }
        }
    }
}
