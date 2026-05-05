<?php

declare(strict_types=1);

namespace Vortos\Authorization\Storage;

use Vortos\Authorization\Contract\AuthorizationVersionStoreInterface;

final class RedisAuthorizationVersionStore implements AuthorizationVersionStoreInterface
{
    private const KEY = 'authorization:user_versions';

    public function __construct(private readonly \Redis $redis)
    {
    }

    public function versionForUser(string $userId): int
    {
        $value = $this->redis->hGet(self::KEY, $this->hashUserId($userId));

        return $value === false ? 0 : (int) $value;
    }

    public function increment(string $userId): int
    {
        return (int) $this->redis->hIncrBy(self::KEY, $this->hashUserId($userId), 1);
    }

    private function hashUserId(string $userId): string
    {
        return hash('sha256', $userId);
    }
}
