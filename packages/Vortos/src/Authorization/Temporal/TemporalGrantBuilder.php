<?php
declare(strict_types=1);

namespace Vortos\Authorization\Temporal;

use Vortos\Authorization\Temporal\Contract\TemporalPermissionStoreInterface;

/**
 * Fluent builder for time-limited permission grants.
 *
 * Usage:
 *   $this->authorization->grant($userId, 'beta.feature')->until(new \DateTimeImmutable('+30 days'));
 */
final class TemporalGrantBuilder
{
    public function __construct(
        private TemporalPermissionStoreInterface $store,
        private string $userId,
        private string $permission,
    ) {}

    public function until(\DateTimeImmutable $expiresAt): void
    {
        $this->store->grant($this->userId, $this->permission, $expiresAt);
    }

    public function forDays(int $days): void
    {
        $this->until(new \DateTimeImmutable("+{$days} days"));
    }

    public function forHours(int $hours): void
    {
        $this->until(new \DateTimeImmutable("+{$hours} hours"));
    }
}
