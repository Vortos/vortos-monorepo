<?php

declare(strict_types=1);

namespace Vortos\Authorization\Contract;

interface AuthorizationVersionStoreInterface
{
    public function versionForUser(string $userId): int;

    public function increment(string $userId): int;
}
