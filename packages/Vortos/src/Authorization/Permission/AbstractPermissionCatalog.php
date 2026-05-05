<?php

declare(strict_types=1);

namespace Vortos\Authorization\Permission;

abstract class AbstractPermissionCatalog implements PermissionCatalogInterface
{
    public static function grants(): array
    {
        return [];
    }

    public static function meta(): array
    {
        return [];
    }

    /**
     * @return array{label: string, description: ?string, dangerous: bool, bypassable: bool}
     */
    protected static function describe(
        string $label,
        ?string $description = null,
        bool $dangerous = false,
        ?bool $bypassable = null,
    ): array {
        return [
            'label' => $label,
            'description' => $description,
            'dangerous' => $dangerous,
            'bypassable' => $bypassable ?? false,
        ];
    }

    /**
     * @return array{label: string, description: ?string, dangerous: bool, bypassable: bool}
     */
    protected static function dangerous(string $label, ?string $description = null): array
    {
        return self::describe($label, $description, true, false);
    }

    /**
     * @return array{label: string, description: ?string, dangerous: bool, bypassable: bool}
     */
    protected static function bypassable(
        string $label,
        ?string $description = null,
        bool $dangerous = false,
    ): array {
        return self::describe($label, $description, $dangerous, true);
    }
}
