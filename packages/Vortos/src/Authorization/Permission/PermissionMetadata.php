<?php

declare(strict_types=1);

namespace Vortos\Authorization\Permission;

final class PermissionMetadata
{
    public function __construct(
        public readonly string $permission,
        public readonly string $resource,
        public readonly string $action,
        public readonly string $scope,
        public readonly string $label,
        public readonly ?string $description,
        public readonly bool $dangerous,
        public readonly bool $bypassable,
        public readonly ?string $group,
        public readonly string $catalogClass,
    ) {
    }
}
