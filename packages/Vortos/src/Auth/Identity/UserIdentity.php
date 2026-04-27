<?php
declare(strict_types=1);

namespace Vortos\Auth\Identity;

use Vortos\Auth\Contract\UserIdentityInterface;

final readonly class UserIdentity implements UserIdentityInterface
{
    /**
     * @param string   $id         User ID from JWT 'sub' claim
     * @param string[] $roles      Roles from JWT 'roles' claim
     * @param array    $attributes Extra claims from JWT payload (plan, tier, org_id, etc.)
     */
    public function __construct(
        private string $id,
        private array $roles = [],
        private array $attributes = [],
    ) {}

    public function id(): string { return $this->id; }
    public function roles(): array { return $this->roles; }
    public function isAuthenticated(): bool { return true; }
    public function hasRole(string $role): bool { return in_array($role, $this->roles, true); }
    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }
}
