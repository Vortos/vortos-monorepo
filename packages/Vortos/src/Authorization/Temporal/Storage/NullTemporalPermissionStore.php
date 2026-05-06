<?php

declare(strict_types=1);

namespace Vortos\Authorization\Temporal\Storage;

use Vortos\Authorization\Temporal\Contract\TemporalPermissionStoreInterface;

final class NullTemporalPermissionStore implements TemporalPermissionStoreInterface
{
    public function grant(string $userId, string $permission, \DateTimeImmutable $expiresAt): void {}

    public function revoke(string $userId, string $permission): void {}

    public function isValid(string $userId, string $permission): bool
    {
        return false;
    }

    public function getExpiry(string $userId, string $permission): ?\DateTimeImmutable
    {
        return null;
    }

    public function activeGrantsForUser(string $userId): array
    {
        return [];
    }
}
