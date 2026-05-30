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
     * Example: $identity->getAttribute('plan') → 'pro'
     */
    public function getAttribute(string $key, mixed $default = null): mixed;

    /**
     * Return the claims to embed in the JWT 'attrs' payload namespace.
     *
     * Everything returned here is serialized into the signed token and is
     * readable by anyone who holds it — do not include secrets or PII.
     *
     * @return array<string, mixed>
     */
    public function getClaims(): array;
}
