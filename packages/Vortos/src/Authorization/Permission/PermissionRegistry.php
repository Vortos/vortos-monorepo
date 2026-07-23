<?php

declare(strict_types=1);

namespace Vortos\Authorization\Permission;

use Vortos\Authorization\Contract\PermissionRegistryInterface;

final class PermissionRegistry implements PermissionRegistryInterface
{
    /**
     * @param array<string, array{
     *     permission: string,
     *     resource: string,
     *     action: string,
     *     scope: string,
     *     label: string,
     *     description: ?string,
     *     dangerous: bool,
     *     bypassable: bool,
     *     policyRequired?: bool,
     *     selfEnforced?: bool,
     *     group: ?string,
     *     catalogClass: string
     * }> $permissions
     * @param array<string, string[]> $defaultGrants
     * @param array<string, string[]> $implications
     */
    public function __construct(
        private readonly array $permissions,
        private readonly array $defaultGrants = [],
        private readonly array $implications = [],
    ) {
    }

    public function all(): array
    {
        return array_keys($this->permissions);
    }

    public function exists(string $permission): bool
    {
        return isset($this->permissions[$permission]);
    }

    public function metadata(string $permission): ?PermissionMetadata
    {
        $metadata = $this->permissions[$permission] ?? null;

        if ($metadata === null) {
            return null;
        }

        return new PermissionMetadata(
            $metadata['permission'],
            $metadata['resource'],
            $metadata['action'],
            $metadata['scope'],
            $metadata['label'],
            $metadata['description'],
            $metadata['dangerous'],
            $metadata['bypassable'],
            $metadata['group'],
            $metadata['catalogClass'],
            (bool) ($metadata['policyRequired'] ?? false),
            (bool) ($metadata['selfEnforced'] ?? false),
        );
    }

    public function defaultGrants(): array
    {
        return $this->defaultGrants;
    }

    public function implications(): array
    {
        return $this->implications;
    }
}
