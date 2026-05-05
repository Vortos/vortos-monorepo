<?php

declare(strict_types=1);

namespace Vortos\Authorization\Contract;

interface AuthorizationCacheInvalidatorInterface
{
    public function invalidateUser(string $userId): void;
}
