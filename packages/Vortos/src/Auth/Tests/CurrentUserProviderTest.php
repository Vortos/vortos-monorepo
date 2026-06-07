<?php

declare(strict_types=1);

namespace Vortos\Auth\Tests;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\Identity\AnonymousIdentity;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Auth\Identity\UserIdentity;
use Vortos\Cache\Adapter\ArrayAdapter;

final class CurrentUserProviderTest extends TestCase
{
    private ArrayAdapter $arrayAdapter;
    private CurrentUserProvider $provider;

    protected function setUp(): void
    {
        $this->arrayAdapter = new ArrayAdapter();
        $this->provider = new CurrentUserProvider($this->arrayAdapter);
    }

    protected function tearDown(): void
    {
        $this->arrayAdapter->clear();
    }

    public function test_returns_anonymous_identity_when_no_identity_set(): void
    {
        $identity = $this->provider->get();

        $this->assertInstanceOf(AnonymousIdentity::class, $identity);
        $this->assertFalse($identity->isAuthenticated());
    }

    public function test_returns_user_identity_when_set(): void
    {
        $expected = new UserIdentity('user-123', ['ROLE_USER']);
        $this->arrayAdapter->set('auth:identity', $expected);

        $identity = $this->provider->get();

        $this->assertInstanceOf(UserIdentity::class, $identity);
        $this->assertTrue($identity->isAuthenticated());
        $this->assertEquals('user-123', $identity->id());
    }

    public function test_returns_anonymous_after_array_adapter_cleared(): void
    {
        $this->arrayAdapter->set('auth:identity', new UserIdentity('user-1', []));
        $this->arrayAdapter->clear(); // simulates end of request in worker mode

        $identity = $this->provider->get();

        $this->assertInstanceOf(AnonymousIdentity::class, $identity);
    }

    public function test_returns_anonymous_when_invalid_value_stored(): void
    {
        // If something puts a non-identity value in the key, should degrade gracefully
        $this->arrayAdapter->set('auth:identity', 'not-an-identity-object');

        $identity = $this->provider->get();

        $this->assertInstanceOf(AnonymousIdentity::class, $identity);
    }
}
