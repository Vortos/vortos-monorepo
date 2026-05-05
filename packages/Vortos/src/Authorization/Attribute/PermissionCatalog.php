<?php

declare(strict_types=1);

namespace Vortos\Authorization\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class PermissionCatalog
{
    public function __construct(
        public readonly string $resource,
        public readonly ?string $group = null,
    ) {
    }
}
