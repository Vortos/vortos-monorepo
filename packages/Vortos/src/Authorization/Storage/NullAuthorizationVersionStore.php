<?php

declare(strict_types=1);

namespace Vortos\Authorization\Storage;

use Vortos\Authorization\Contract\AuthorizationVersionStoreInterface;

final class NullAuthorizationVersionStore implements AuthorizationVersionStoreInterface
{
    public function versionForUser(string $userId): int
    {
        return 0;
    }

    public function increment(string $userId): int
    {
        return 0;
    }
}
