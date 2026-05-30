<?php

declare(strict_types=1);

namespace Vortos\Authorization\Command;

use Vortos\Auth\Identity\UserIdentity;
use Vortos\Authorization\Contract\AuthorizationVersionStoreInterface;
use Vortos\Authorization\Contract\UserRoleStoreInterface;
use Vortos\Cache\Adapter\ArrayAdapter;

final class AuthCommandIdentityFactory
{
    public function __construct(
        private readonly UserRoleStoreInterface $userRoles,
        private readonly AuthorizationVersionStoreInterface $versions,
        private readonly ArrayAdapter $arrayAdapter,
    ) {
    }

    /**
     * Creates an identity for CLI authorization simulation and populates the
     * request-scoped authz version so PolicyEngine reads it via RequestAuthzVersionProvider.
     *
     * @param string[] $jwtRoles
     */
    public function create(string $userId, array $jwtRoles = [], ?int $authzVersion = null): UserIdentity
    {
        $roles = $jwtRoles !== [] ? $jwtRoles : $this->userRoles->rolesForUser($userId);
        $authzVersion ??= $this->versions->versionForUser($userId);

        $this->arrayAdapter->set('auth:authz_version', $authzVersion);

        return new UserIdentity($userId, $roles);
    }
}
