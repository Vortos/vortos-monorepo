<?php

declare(strict_types=1);

namespace Vortos\Auth\Scim\Token;

final readonly class ScimTokenRecord
{
    /**
     * @param list<string> $scopes        e.g. ['scim:users:read', 'scim:users:write', 'scim:groups:read', 'scim:groups:write']
     * @param list<string> $allowedCidrs  IP allowlist in CIDR notation; empty = any IP allowed
     * @param list<string> $allowedRoles  Provisionable role ceiling; empty = defer to scope check only
     */
    public function __construct(
        public string              $id,
        public string              $tenantId,
        public string              $hashedToken,
        public array               $scopes,
        public array               $allowedCidrs,
        public bool                $active,
        public \DateTimeImmutable   $createdAt,
        public ?\DateTimeImmutable  $expiresAt,
        public ?\DateTimeImmutable  $lastUsedAt,
        public array               $allowedRoles = [],
    ) {}

    public function isExpired(): bool
    {
        return $this->expiresAt !== null && $this->expiresAt < new \DateTimeImmutable();
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    public function hasAllScopes(array $scopes): bool
    {
        foreach ($scopes as $scope) {
            if (!$this->hasScope($scope)) {
                return false;
            }
        }
        return true;
    }

    public function isRolePermitted(string $role): bool
    {
        if (!$this->hasScope('scim:role:' . $role)) {
            return false;
        }

        if ($this->allowedRoles !== []) {
            return in_array($role, $this->allowedRoles, true);
        }

        return true;
    }
}
