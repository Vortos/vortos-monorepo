<?php

declare(strict_types=1);

namespace App\User\Presentation\Policy;

use Vortos\Authorization\Attribute\AsPolicy;
use Vortos\Authorization\Context\AuthorizationContext;
use Vortos\Authorization\Contract\PolicyInterface;

#[AsPolicy(resource: 'users')]
final class UserPolicy implements PolicyInterface
{
    public function can(
        AuthorizationContext $auth,
        string $action,
        string $scope,
        mixed $resource = null,
    ): bool {
        return match ($action) {
            'read'   => true,
            'create' => true,
            'update' => $scope === 'own',
            'delete' => $scope === 'own',
            default  => false,
        };
    }
}
