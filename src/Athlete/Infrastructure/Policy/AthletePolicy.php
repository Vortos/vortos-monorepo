<?php

declare(strict_types=1);

namespace App\Athlete\Infrastructure\Policy;

use Vortos\Authorization\Attribute\AsPolicy;
use Vortos\Authorization\Context\AuthorizationContext;
use Vortos\Authorization\Contract\PolicyInterface;

#[AsPolicy(resource: 'athletes')]
final class AthletePolicy implements PolicyInterface
{
    public function can(
        AuthorizationContext $auth,
        string $action,
        string $scope,
        mixed $resource = null,
    ): bool {
        return match ($action) {
            'list'   => $auth->atLeast('ROLE_USER'),
            'read'   => $auth->atLeast('ROLE_USER'),
            'create' => $auth->atLeast('ROLE_COACH'),
            'update' => $this->canUpdate($auth, $scope, $resource),
            'delete' => $auth->hasAnyRole(['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']),
            default  => false,
        };
    }

    private function canUpdate(AuthorizationContext $auth, string $scope, mixed $resource): bool
    {
        if ($auth->atLeast('ROLE_FEDERATION_ADMIN')) {
            return true;
        }

        if ($scope === 'own' && $resource !== null) {
            // $resource is the athleteId from the route parameter
            return $resource === $auth->user()->id();
        }

        return false;
    }
}
