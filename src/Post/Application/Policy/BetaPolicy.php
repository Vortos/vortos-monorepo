<?php

namespace App\Post\Application\Policy;

use Vortos\Auth\Contract\UserIdentityInterface;
use Vortos\Authorization\Attribute\AsPolicy;
use Vortos\Authorization\Contract\PolicyInterface;
use Vortos\Authorization\Temporal\TemporalAuthorizationManager;

#[AsPolicy(resource: 'beta')]
final class BetaPolicy implements PolicyInterface
{
    public function __construct(
        private TemporalAuthorizationManager $temporal,
    ) {}

    public function can(
        UserIdentityInterface $identity,
        string $action,
        string $scope,
        mixed $resource = null,
    ): bool {
        // action is the second segment: 'analytics_v2', 'ai_suggestions', etc.
        $permission = "beta.{$action}";

        return $this->temporal->isValid($identity->id(), $permission);
    }


}