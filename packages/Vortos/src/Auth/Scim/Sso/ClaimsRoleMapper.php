<?php

declare(strict_types=1);

namespace Vortos\Auth\Scim\Sso;

use Psr\Log\LoggerInterface;

final class ClaimsRoleMapper
{
    /** @param ClaimsRoleMapping[] $mappings */
    public function __construct(
        private readonly array $mappings,
        private readonly ?string $defaultRole = null,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * @param string[] $groups
     * @return string[]
     */
    public function mapGroupsToRoles(array $groups): array
    {
        $roles = [];

        foreach ($groups as $group) {
            foreach ($this->mappings as $mapping) {
                try {
                    if ($mapping->matchesGroup($group)) {
                        $roles[] = $mapping->platformRole;
                    }
                } catch (RoleMappingException $e) {
                    $this->logger?->error('role_mapping.match_failed', [
                        'pattern' => $mapping->idpIdentifier,
                        'group' => $group,
                        'exception' => $e->getMessage(),
                    ]);
                    return [];
                }
            }
        }

        if ($roles === [] && $this->defaultRole !== null) {
            $roles[] = $this->defaultRole;
        }

        return array_values(array_unique($roles));
    }

    /**
     * @param string[] $claims
     * @return string[]
     */
    public function mapClaimsToRoles(array $claims): array
    {
        $roles = [];

        foreach ($claims as $claim) {
            foreach ($this->mappings as $mapping) {
                try {
                    if ($mapping->matchesClaim($claim)) {
                        $roles[] = $mapping->platformRole;
                    }
                } catch (RoleMappingException $e) {
                    $this->logger?->error('role_mapping.match_failed', [
                        'pattern' => $mapping->idpIdentifier,
                        'claim' => $claim,
                        'exception' => $e->getMessage(),
                    ]);
                    return [];
                }
            }
        }

        if ($roles === [] && $this->defaultRole !== null) {
            $roles[] = $this->defaultRole;
        }

        return array_values(array_unique($roles));
    }

    public function mapGroupDisplayNameToRole(string $displayName): ?string
    {
        foreach ($this->mappings as $mapping) {
            try {
                if ($mapping->matchesGroup($displayName)) {
                    return $mapping->platformRole;
                }
            } catch (RoleMappingException $e) {
                $this->logger?->error('role_mapping.match_failed', [
                    'pattern' => $mapping->idpIdentifier,
                    'display_name' => $displayName,
                    'exception' => $e->getMessage(),
                ]);
                return null;
            }
        }

        return null;
    }
}
