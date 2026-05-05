<?php

declare(strict_types=1);

namespace Vortos\Authorization\Resolver;

use Vortos\Authorization\Contract\AuthorizationCacheInvalidatorInterface;

final class NullAuthorizationCacheInvalidator implements AuthorizationCacheInvalidatorInterface
{
    public function invalidateUser(string $userId): void
    {
    }
}
