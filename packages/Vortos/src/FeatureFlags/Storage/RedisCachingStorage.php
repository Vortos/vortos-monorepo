<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Storage;

use Psr\SimpleCache\CacheInterface;
use Vortos\FeatureFlags\FeatureFlag;

final class RedisCachingStorage implements FlagStorageInterface
{
    private const CACHE_KEY_PREFIX = 'vortos_feature_flags_';
    private const LOCK_TTL_SECONDS = 5;

    public function __construct(
        private readonly FlagStorageInterface $inner,
        private readonly ?CacheInterface $cache = null,
        private readonly int $ttl = 60,
        private readonly string $tenantId = 'default',
        private readonly ?\Redis $redis = null,
    ) {}

    public function findAll(): array
    {
        if ($this->cache === null) {
            return $this->inner->findAll();
        }

        $cacheKey = $this->cacheKey();
        $raw = $this->cache->get($cacheKey);

        if ($raw !== null && is_string($raw)) {
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $decoded = null; // Corrupt cache entry — treat as miss
            }
            if (is_array($decoded)) {
                return array_map(fn(array $d) => FeatureFlag::fromArray($d), $decoded);
            }
        }

        // Distributed lock: only one worker regenerates; others wait briefly then re-check.
        $lockKey = $cacheKey . ':lock';
        $locked  = $this->redis !== null
            ? (bool) $this->redis->set($lockKey, '1', ['NX', 'EX' => self::LOCK_TTL_SECONDS])
            : true;

        if (!$locked) {
            usleep(100_000); // 100ms — wait for the winning worker to populate the cache
            $raw = $this->cache->get($cacheKey);
            if ($raw !== null && is_string($raw)) {
                try {
                    $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    $decoded = null; // Corrupt cache entry — treat as miss
                }
                if (is_array($decoded)) {
                    return array_map(fn(array $d) => FeatureFlag::fromArray($d), $decoded);
                }
            }
            // Lock holder hasn't written yet — fall through and query ourselves
        }

        try {
            $flags = $this->inner->findAll();
            $this->cache->set($cacheKey, json_encode(array_map(fn(FeatureFlag $f) => $f->toArray(), $flags), JSON_THROW_ON_ERROR), $this->ttl);
        } finally {
            if ($locked && $this->redis !== null) {
                $this->redis->del($lockKey);
            }
        }

        return $flags;
    }

    public function findByName(string $name): ?FeatureFlag
    {
        foreach ($this->findAll() as $flag) {
            if ($flag->name === $name) {
                return $flag;
            }
        }

        return null;
    }

    public function save(FeatureFlag $flag): void
    {
        $this->inner->save($flag);
        $this->invalidate();
    }

    public function delete(string $name): void
    {
        $this->inner->delete($name);
        $this->invalidate();
    }

    private function invalidate(): void
    {
        $this->cache?->delete($this->cacheKey());
    }

    private function cacheKey(): string
    {
        return self::CACHE_KEY_PREFIX . $this->tenantId;
    }
}
