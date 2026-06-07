<?php

declare(strict_types=1);

namespace Vortos\Auth\Tests;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\Identity\AnonymousIdentity;
use Vortos\Auth\Identity\UserIdentity;

final class UserIdentityTest extends TestCase
{
    public function test_user_identity_is_authenticated(): void
    {
        $identity = new UserIdentity('user-1', ['ROLE_USER']);

        $this->assertTrue($identity->isAuthenticated());
        $this->assertEquals('user-1', $identity->id());
        $this->assertEquals(['ROLE_USER'], $identity->roles());
    }

    public function test_user_identity_has_role(): void
    {
        $identity = new UserIdentity('user-1', ['ROLE_USER', 'ROLE_ADMIN']);

        $this->assertTrue($identity->hasRole('ROLE_ADMIN'));
        $this->assertFalse($identity->hasRole('ROLE_SUPER'));
    }

    public function test_anonymous_identity_is_not_authenticated(): void
    {
        $identity = new AnonymousIdentity();

        $this->assertFalse($identity->isAuthenticated());
        $this->assertEquals('', $identity->id());
        $this->assertEquals([], $identity->roles());
        $this->assertFalse($identity->hasRole('ROLE_USER'));
    }
}
