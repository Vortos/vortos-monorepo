<?php

declare(strict_types=1);

namespace Vortos\Authorization\Command;

use Vortos\Auth\Identity\UserIdentity;
use Vortos\Authorization\Contract\AuthorizationVersionStoreInterface;
use Vortos\Authorization\Contract\UserRoleStoreInterface;

final class AuthCommandIdentityFactory
{
    public function __construct(
        private readonly UserRoleStoreInterface $userRoles,
        private readonly AuthorizationVersionStoreInterface $versions,
    ) {
    }

    /**
     * @param string[] $jwtRoles
     */
    public function create(string $userId, array $jwtRoles = [], ?int $authzVersion = null): UserIdentity
    {
        $roles = $jwtRoles !== [] ? $jwtRoles : $this->userRoles->rolesForUser($userId);
        $authzVersion ??= $this->versions->versionForUser($userId);

        return new UserIdentity($userId, $roles, ['authz_version' => $authzVersion]);
    }
}
