<?php

declare(strict_types=1);

namespace Vortos\Authorization\DependencyInjection;

/**
 * Fluent configuration for vortos-authorization.
 *
 * Usage in config/authorization.php:
 *
 *   return static function(VortosAuthorizationConfig $config): void {
 *       $config->roleHierarchy([
 *           'ROLE_SUPER_ADMIN'      => ['ROLE_ADMIN'],
 *           'ROLE_ADMIN'            => ['ROLE_FEDERATION_ADMIN'],
 *           'ROLE_FEDERATION_ADMIN' => ['ROLE_COACH', 'ROLE_JUDGE'],
 *           'ROLE_COACH'            => ['ROLE_USER'],
 *           'ROLE_JUDGE'            => ['ROLE_USER'],
 *       ]);
 *   };
 *
 * No config file required for basic usage — empty hierarchy works,
 * policies just use exact role matching.
 */
final class VortosAuthorizationConfig
{
    /** @var array<string, string[]> */
    private array $roleHierarchy = [];

    /**
     * Define role inheritance hierarchy.
     *
     * Format: ['PARENT_ROLE' => ['CHILD_ROLE_1', 'CHILD_ROLE_2']]
     *
     * When a user has PARENT_ROLE, RoleVoter::hasRole() also returns true
     * for all CHILD_ROLEs — recursively through the hierarchy.
     *
     * @param array<string, string[]> $hierarchy
     */
    public function roleHierarchy(array $hierarchy): static
    {
        $this->roleHierarchy = $hierarchy;
        return $this;
    }

    /** @internal */
    public function toArray(): array
    {
        return ['role_hierarchy' => $this->roleHierarchy];
    }
}
