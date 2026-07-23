<?php

declare(strict_types=1);

namespace Vortos\Authorization\Contract;

use Vortos\Authorization\Permission\PermissionMetadata;

interface PermissionRegistryInterface
{
    /**
     * @return string[]
     */
    public function all(): array;

    public function exists(string $permission): bool;

    public function metadata(string $permission): ?PermissionMetadata;

    /**
     * @return array<string, string[]>
     */
    public function defaultGrants(): array;

    /**
     * Direct implications declared by catalogs: permission => permissions it
     * carries. Resolved transitively by PermissionImplicationExpander.
     *
     * @return array<string, string[]>
     */
    public function implications(): array;
}
