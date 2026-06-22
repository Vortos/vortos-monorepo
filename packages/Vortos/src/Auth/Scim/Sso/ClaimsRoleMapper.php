<?php

declare(strict_types=1);

namespace Vortos\Auth\Scim\Sso;

/**
 * Maps OIDC/SAML group or scope claims → platform role slugs.
 *
 * The Authorization engine already understands role slugs (e.g. 'flags.admin',
 * 'flags.viewer', 'flags.deploy'). This mapper bridges the IdP's representation
 * to those slugs so the same authz engine works regardless of identity provider.
 *
 * Configuration is a simple list of `ClaimsRoleMapping` rules evaluated in order;
 * the first matching rule wins. This keeps the mapper stateless and easily testable.
 */
final class ClaimsRoleMapper
{
    /** @param ClaimsRoleMapping[] $mappings */
    public function __construct(
        private readonly array $mappings,
        private readonly ?string $defaultRole = null,
    ) {}

    /**
     * Map a list of IdP group IDs/names to platform role slugs.
     *
     * @param string[] $groups
     * @return string[] Deduplicated platform role slugs
     */
    public function mapGroupsToRoles(array $groups): array
    {
        $roles = [];

        foreach ($groups as $group) {
            foreach ($this->mappings as $mapping) {
                if ($mapping->matchesGroup($group)) {
                    $roles[] = $mapping->platformRole;
                }
            }
        }

        if ($roles === [] && $this->defaultRole !== null) {
            $roles[] = $this->defaultRole;
        }

        return array_values(array_unique($roles));
    }

    /**
     * Map a list of OIDC scope/claim values to platform role slugs.
     *
     * @param string[] $claims
     * @return string[]
     */
    public function mapClaimsToRoles(array $claims): array
    {
        $roles = [];

        foreach ($claims as $claim) {
            foreach ($this->mappings as $mapping) {
                if ($mapping->matchesClaim($claim)) {
                    $roles[] = $mapping->platformRole;
                }
            }
        }

        if ($roles === [] && $this->defaultRole !== null) {
            $roles[] = $this->defaultRole;
        }

        return array_values(array_unique($roles));
    }

    /**
     * Map an IdP group display name to a single platform role (for SCIM group provisioning).
     * Returns null if no mapping is found.
     */
    public function mapGroupDisplayNameToRole(string $displayName): ?string
    {
        foreach ($this->mappings as $mapping) {
            if ($mapping->matchesGroup($displayName)) {
                return $mapping->platformRole;
            }
        }

        return $this->defaultRole;
    }
}
