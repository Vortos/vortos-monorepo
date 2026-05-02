<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Storage;

use Psr\SimpleCache\CacheInterface;
use Vortos\FeatureFlags\FeatureFlag;

final class RedisCachingStorage implements FlagStorageInterface
{
    private const CACHE_KEY = 'vortos_feature_flags_all';

    public function __construct(
        private readonly FlagStorageInterface $inner,
        private readonly ?CacheInterface $cache = null,
        private readonly int $ttl = 60,
    ) {}

    public function findAll(): array
    {
        if ($this->cache === null) {
            return $this->inner->findAll();
        }

        $cached = $this->cache->get(self::CACHE_KEY);

        if ($cached !== null) {
            return $cached;
        }

        $flags = $this->inner->findAll();
        $this->cache->set(self::CACHE_KEY, $flags, $this->ttl);

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
        $this->cache?->delete(self::CACHE_KEY);
    }
}
