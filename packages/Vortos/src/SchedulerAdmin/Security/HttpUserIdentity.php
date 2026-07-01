<?php

declare(strict_types=1);

namespace Vortos\SchedulerAdmin\Security;

use Vortos\Auth\Contract\UserIdentityInterface;

/**
 * Thin adapter that implements UserIdentityInterface by delegating to
 * a wrapped UserIdentityInterface. Used to pass the admin user to
 * ScheduleService (which expects UserIdentityInterface) without
 * coupling to the concrete identity type from vortos-auth.
 */
final readonly class HttpUserIdentity implements UserIdentityInterface
{
    public function __construct(private readonly UserIdentityInterface $inner) {}

    public function id(): string
    {
        return $this->inner->id();
    }

    public function roles(): array
    {
        return $this->inner->roles();
    }

    public function isAuthenticated(): bool
    {
        return $this->inner->isAuthenticated();
    }

    public function hasRole(string $role): bool
    {
        return $this->inner->hasRole($role);
    }

    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->inner->getAttribute($key, $default);
    }

    public function getClaims(): array
    {
        return $this->inner->getClaims();
    }
}
