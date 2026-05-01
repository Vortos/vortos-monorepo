<?php

declare(strict_types=1);

namespace App\Athlete\Infrastructure\Policy;

use Vortos\Auth\Contract\UserIdentityInterface;
use Vortos\Authorization\Attribute\AsPolicy;
use Vortos\Authorization\Contract\PolicyInterface;
use Vortos\Authorization\Voter\RoleVoter;

#[AsPolicy(resource: 'athletes')]
final class AthletePolicy implements PolicyInterface
{
    public function __construct(private RoleVoter $roles) {}

    public function can(
        UserIdentityInterface $identity,
        string $action,
        string $scope,
        mixed $resource = null,
    ): bool {
        return match ($action) {
            'list'   => $this->roles->atLeast($identity, 'ROLE_USER'),
            'read'   => $this->roles->atLeast($identity, 'ROLE_USER'),
            'create' => $this->roles->atLeast($identity, 'ROLE_COACH'),
            'update' => $this->canUpdate($identity, $scope, $resource),
            'delete' => $this->roles->hasAny($identity, ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']),
            default  => false,
        };
    }

    private function canUpdate(UserIdentityInterface $identity, string $scope, mixed $resource): bool
    {
        if ($this->roles->atLeast($identity, 'ROLE_FEDERATION_ADMIN')) {
            return true;
        }

        if ($scope === 'own' && $resource !== null) {
            // $resource is the athleteId from the route parameter
            return $resource === $identity->id();
        }

        return false;
    }
}
