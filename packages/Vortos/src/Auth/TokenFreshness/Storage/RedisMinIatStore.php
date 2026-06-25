<?php
declare(strict_types=1);

namespace Vortos\Auth\TokenFreshness\Storage;

use Vortos\Auth\TokenFreshness\MinIatStoreInterface;

final class RedisMinIatStore implements MinIatStoreInterface
{
    private const KEY = 'auth:global:min_iat';

    public function __construct(private \Redis $redis) {}

    public function get(): ?int
    {
        $value = $this->redis->get(self::KEY);

        return $value !== false ? (int) $value : null;
    }

    public function set(int $epoch): void
    {
        $this->redis->set(self::KEY, (string) $epoch);
    }
}
