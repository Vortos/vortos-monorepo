<?php

declare(strict_types=1);

namespace Vortos\Authorization\Storage;

use Vortos\Authorization\Contract\AuthorizationVersionStoreInterface;

final class RedisAuthorizationVersionStore implements AuthorizationVersionStoreInterface
{
    private const KEY_PREFIX = 'authorization:user_version:';
    private const TTL = 2_592_000; // 30 days — resets on each increment

    public function __construct(private readonly \Redis $redis)
    {
    }

    public function versionForUser(string $userId): int
    {
        $value = $this->redis->get(self::KEY_PREFIX . $this->hashUserId($userId));

        return $value === false ? 0 : (int) $value;
    }

    public function increment(string $userId): int
    {
        $key = self::KEY_PREFIX . $this->hashUserId($userId);
        $version = (int) $this->redis->incr($key);
        // Refresh TTL on every role change so active users never expire mid-session.
        $this->redis->expire($key, self::TTL);

        return $version;
    }

    private function hashUserId(string $userId): string
    {
        return hash('sha256', $userId);
    }
}
