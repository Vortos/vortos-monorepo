<?php

declare(strict_types=1);

namespace Vortos\Authorization\Permission;

interface PermissionCatalogInterface
{
    /**
     * @return array<string, string[]>
     */
    public static function grants(): array;

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function meta(): array;
}
