<?php

declare(strict_types=1);

namespace App\User\Presentation\Permission;

use Vortos\Authorization\Attribute\PermissionCatalog;

#[PermissionCatalog(resource: 'users', group: 'User Management')]
final class UserPermission
{
    public const READ   = 'users.read.any';
    public const CREATE = 'users.create.any';
    public const UPDATE = 'users.update.own';
    public const DELETE = 'users.delete.own';
}
