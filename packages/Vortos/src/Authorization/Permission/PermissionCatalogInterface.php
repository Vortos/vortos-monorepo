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

    /**
     * Permissions each permission carries with it, e.g. a review capability
     * implying the view capability it presupposes.
     *
     * Expansion happens when permissions are resolved, never when they are
     * granted, so role grant lists stay exactly as an administrator chose them.
     *
     * @return array<string, string[]>
     */
    public static function implies(): array;
}
