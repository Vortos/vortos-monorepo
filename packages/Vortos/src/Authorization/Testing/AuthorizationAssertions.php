<?php

declare(strict_types=1);

namespace Vortos\Authorization\Testing;

use Vortos\Auth\Contract\UserIdentityInterface;
use Vortos\Authorization\Decision\AuthorizationDecision;
use Vortos\Authorization\Engine\PolicyEngine;

trait AuthorizationAssertions
{
    abstract protected function authorizationEngine(): PolicyEngine;

    protected function assertAllowed(
        UserIdentityInterface $identity,
        string $permission,
        mixed $resource = null,
    ): void {
        $decision = $this->authorizationEngine()->decide($identity, $permission, $resource);

        $this->assertTrue(
            $decision->allowed(),
            sprintf(
                'Expected "%s" to be allowed, denied because "%s".',
                $permission,
                $decision->reason(),
            ),
        );
    }

    protected function assertDenied(
        UserIdentityInterface $identity,
        string $permission,
        mixed $resource = null,
    ): AuthorizationDecision {
        $decision = $this->authorizationEngine()->decide($identity, $permission, $resource);

        $this->assertFalse(
            $decision->allowed(),
            sprintf('Expected "%s" to be denied.', $permission),
        );

        return $decision;
    }

    protected function assertDeniedBecause(
        UserIdentityInterface $identity,
        string $permission,
        string $reason,
        mixed $resource = null,
    ): void {
        $decision = $this->assertDenied($identity, $permission, $resource);

        $this->assertSame($reason, $decision->reason());
    }
}
