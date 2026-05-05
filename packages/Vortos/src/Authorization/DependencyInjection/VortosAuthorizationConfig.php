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

    private bool $authzVersionCheck = true;

    private bool $breakGlassBypass = false;

    private string $breakGlassRole = 'ROLE_SUPER_ADMIN';

    private bool $traceDecisions = false;

    private bool $traceResolver = false;

    private bool $traceAdminMutations = false;

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

    public function authzVersionCheck(bool $enabled = true): static
    {
        $this->authzVersionCheck = $enabled;
        return $this;
    }

    public function superAdminBypass(bool $enabled = true): static
    {
        return $this->breakGlassBypass($enabled);
    }

    public function superAdminRole(string $role): static
    {
        return $this->breakGlassRole($role);
    }

    public function superAdminBypassDangerous(bool $enabled = true): static
    {
        if ($enabled) {
            throw new \LogicException('superAdminBypassDangerous() is no longer supported. Mark individual catalog permissions as bypassable instead.');
        }

        return $this;
    }

    public function breakGlassBypass(bool $enabled = true): static
    {
        $this->breakGlassBypass = $enabled;
        return $this;
    }

    public function breakGlassRole(string $role): static
    {
        $this->breakGlassRole = $role;
        return $this;
    }

    public function traceDecisions(bool $enabled = true): static
    {
        $this->traceDecisions = $enabled;
        return $this;
    }

    public function traceResolver(bool $enabled = true): static
    {
        $this->traceResolver = $enabled;
        return $this;
    }

    public function traceAdminMutations(bool $enabled = true): static
    {
        $this->traceAdminMutations = $enabled;
        return $this;
    }

    /** @internal */
    public function toArray(): array
    {
        return [
            'role_hierarchy' => $this->roleHierarchy,
            'authz_version_check' => $this->authzVersionCheck,
            'break_glass_bypass' => $this->breakGlassBypass,
            'break_glass_role' => $this->breakGlassRole,
            'trace_decisions' => $this->traceDecisions,
            'trace_resolver' => $this->traceResolver,
            'trace_admin_mutations' => $this->traceAdminMutations,
        ];
    }
}
