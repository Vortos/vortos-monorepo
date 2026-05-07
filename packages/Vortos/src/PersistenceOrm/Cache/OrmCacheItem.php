<?php

declare(strict_types=1);

namespace Vortos\PersistenceOrm\Cache;

use Psr\Cache\CacheItemInterface;

/**
 * PSR-6 cache item for Doctrine ORM metadata caching.
 *
 * Tracks the key, value, hit status, and expiration. getTtl() converts
 * expiration to a TTL integer suitable for the underlying PSR-16 cache.
 */
final class OrmCacheItem implements CacheItemInterface
{
    private mixed $value;
    private bool $isHit;
    private ?int $expiresAt = null;

    public function __construct(string $key, bool $isHit, mixed $value)
    {
        $this->key   = $key;
        $this->isHit = $isHit;
        $this->value = $value;
    }

    private string $key;

    public function getKey(): string
    {
        return $this->key;
    }

    public function get(): mixed
    {
        return $this->isHit ? $this->value : null;
    }

    public function isHit(): bool
    {
        return $this->isHit;
    }

    public function set(mixed $value): static
    {
        $this->value = $value;
        $this->isHit = true;
        return $this;
    }

    public function expiresAt(?\DateTimeInterface $expiration): static
    {
        $this->expiresAt = $expiration?->getTimestamp();
        return $this;
    }

    public function expiresAfter(int|\DateInterval|null $time): static
    {
        if ($time === null) {
            $this->expiresAt = null;
        } elseif ($time instanceof \DateInterval) {
            $this->expiresAt = (new \DateTimeImmutable())->add($time)->getTimestamp();
        } else {
            $this->expiresAt = time() + $time;
        }

        return $this;
    }

    public function getTtl(int $defaultTtl): int
    {
        if ($this->expiresAt === null) {
            return $defaultTtl;
        }

        return max(0, $this->expiresAt - time());
    }
}
