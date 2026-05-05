<?php
declare(strict_types=1);

namespace Vortos\Authorization\Temporal\Contract;

/**
 * Stores time-limited permission grants.
 *
 * Critical data — DB primary + Redis cache.
 * Default: Redis with TTL (auto-expires, no cron needed).
 * Replace with DbTemporalPermissionStore for persistence guarantees.
 */
interface TemporalPermissionStoreInterface
{
    public function grant(
        string $userId,
        string $permission,
        \DateTimeImmutable $expiresAt,
    ): void;

    public function revoke(string $userId, string $permission): void;

    public function isValid(string $userId, string $permission): bool;

    public function getExpiry(string $userId, string $permission): ?\DateTimeImmutable;

    /**
     * @return string[]
     */
    public function activeGrantsForUser(string $userId): array;
}
