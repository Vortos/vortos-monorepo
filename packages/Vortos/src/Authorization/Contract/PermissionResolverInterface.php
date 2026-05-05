<?php

declare(strict_types=1);

namespace Vortos\Authorization\Contract;

use Vortos\Auth\Contract\UserIdentityInterface;
use Vortos\Authorization\Permission\ResolvedPermissions;

interface PermissionResolverInterface
{
    public function resolve(UserIdentityInterface $identity): ResolvedPermissions;

    public function has(UserIdentityInterface $identity, string $permission): bool;
}
