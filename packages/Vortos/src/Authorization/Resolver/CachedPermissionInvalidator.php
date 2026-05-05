<?php

declare(strict_types=1);

namespace Vortos\Authorization\Resolver;

use Vortos\Authorization\Contract\AuthorizationCacheInvalidatorInterface;

final class CachedPermissionInvalidator implements AuthorizationCacheInvalidatorInterface
{
    public function __construct(private readonly CachedPermissionResolver $resolver)
    {
    }

    public function invalidateUser(string $userId): void
    {
        $this->resolver->invalidateUser($userId);
    }
}
