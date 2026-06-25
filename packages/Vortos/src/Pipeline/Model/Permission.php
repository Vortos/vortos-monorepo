<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Model;

final readonly class Permission
{
    public function __construct(
        public PermissionScope $scope,
        public PermissionAccess $access,
    ) {}

    /** @return array{scope: string, access: string} */
    public function toArray(): array
    {
        return [
            'scope' => $this->scope->value,
            'access' => $this->access->value,
        ];
    }
}
