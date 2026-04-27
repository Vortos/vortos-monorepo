<?php
declare(strict_types=1);

namespace Vortos\Authorization\Temporal;

use Vortos\Authorization\Temporal\Contract\TemporalPermissionStoreInterface;

/**
 * Manager for time-limited permission grants.
 *
 * Inject this in command handlers to grant/revoke temporal permissions.
 *
 * Usage:
 *   $this->authorization->grant($userId, 'beta.feature')->until(new \DateTimeImmutable('+30 days'));
 *   $this->authorization->grant($userId, 'beta.feature')->forDays(30);
 *   $this->authorization->revoke($userId, 'beta.feature');
 *   $this->authorization->isValid($userId, 'beta.feature');
 */
final class TemporalAuthorizationManager
{
    public function __construct(
        private TemporalPermissionStoreInterface $store,
    ) {}

    public function grant(string $userId, string|\BackedEnum $permission): TemporalGrantBuilder
    {
        return new TemporalGrantBuilder(
            $this->store,
            $userId,
            $permission instanceof \BackedEnum ? $permission->value : $permission,
        );
    }

    public function revoke(string $userId, string|\BackedEnum $permission): void
    {
        $this->store->revoke(
            $userId,
            $permission instanceof \BackedEnum ? $permission->value : $permission,
        );
    }

    public function isValid(string $userId, string|\BackedEnum $permission): bool
    {
        return $this->store->isValid(
            $userId,
            $permission instanceof \BackedEnum ? $permission->value : $permission,
        );
    }

    public function getExpiry(string $userId, string|\BackedEnum $permission): ?\DateTimeImmutable
    {
        return $this->store->getExpiry(
            $userId,
            $permission instanceof \BackedEnum ? $permission->value : $permission,
        );
    }
}
