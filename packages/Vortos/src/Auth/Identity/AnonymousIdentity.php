<?php
declare(strict_types=1);

namespace Vortos\Auth\Identity;

use Vortos\Auth\Contract\UserIdentityInterface;

final readonly class AnonymousIdentity implements UserIdentityInterface
{
    public function id(): string { return ''; }
    public function roles(): array { return []; }
    public function isAuthenticated(): bool { return false; }
    public function hasRole(string $role): bool { return false; }
    public function getAttribute(string $key, mixed $default = null): mixed { return $default; }
}
