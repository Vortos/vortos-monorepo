<?php

declare(strict_types=1);

namespace Vortos\Authorization\Storage;

use Vortos\Authorization\Contract\EmergencyDenyListInterface;

final class RedisEmergencyDenyList implements EmergencyDenyListInterface
{
    private const KEY = 'authorization:emergency_denied_users';

    public function __construct(private readonly \Redis $redis)
    {
    }

    public function deny(string $userId): void
    {
        $this->redis->sAdd(self::KEY, $this->hashUserId($userId));
    }

    public function allow(string $userId): void
    {
        $this->redis->sRem(self::KEY, $this->hashUserId($userId));
    }

    public function isDenied(string $userId): bool
    {
        return (bool) $this->redis->sIsMember(self::KEY, $this->hashUserId($userId));
    }

    private function hashUserId(string $userId): string
    {
        return hash('sha256', $userId);
    }
}
