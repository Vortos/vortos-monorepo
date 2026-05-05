<?php

declare(strict_types=1);

namespace Tests\Authorization;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\Identity\UserIdentity;
use Vortos\Authorization\Context\AuthorizationContext;
use Vortos\Authorization\Permission\ResolvedPermissions;
use Vortos\Authorization\Voter\RoleVoter;

final class AuthorizationContextTest extends TestCase
{
    public function test_has_checks_resolved_permission_hashmap(): void
    {
        $context = AuthorizationContext::for(
            roles: ['ROLE_USER'],
            permissions: ['orders.read.any'],
        );

        $this->assertTrue($context->has('orders.read.any'));
        $this->assertFalse($context->has('orders.delete.any'));
    }

    public function test_role_helpers_use_role_voter_hierarchy(): void
    {
        $roleVoter = new RoleVoter(['ROLE_ADMIN' => ['ROLE_USER']]);
        $context = new AuthorizationContext(
            new UserIdentity('u1', ['ROLE_ADMIN']),
            new ResolvedPermissions('u1', ['ROLE_ADMIN'], ['ROLE_ADMIN', 'ROLE_USER'], []),
            $roleVoter,
        );

        $this->assertTrue($context->hasRole('ROLE_ADMIN'));
        $this->assertTrue($context->atLeast('ROLE_USER'));
        $this->assertTrue($context->hasAnyRole(['ROLE_SUPPORT', 'ROLE_USER']));
        $this->assertFalse($context->isSuperAdmin());
    }
}
