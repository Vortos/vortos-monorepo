<?php
declare(strict_types=1);

namespace Vortos\Auth\Contract;

interface UserIdentityInterface
{
    public function id(): string;
    public function roles(): array;
    public function isAuthenticated(): bool;
    public function hasRole(string $role): bool;

    /**
     * Get a custom attribute from the identity.
     * Attributes are embedded in the JWT payload as extra claims.
     * Example: $identity->getAttribute('plan') → 'pro'
     */
    public function getAttribute(string $key, mixed $default = null): mixed;
}
