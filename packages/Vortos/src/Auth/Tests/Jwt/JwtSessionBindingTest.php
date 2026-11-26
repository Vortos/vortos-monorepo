<?php

declare(strict_types=1);

namespace Vortos\Auth\Tests\Jwt;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\DependencyInjection\VortosAuthConfig;
use Vortos\Auth\Exception\TokenRevokedException;
use Vortos\Auth\Identity\UserIdentity;
use Vortos\Auth\Jwt\JwtConfig;
use Vortos\Auth\Jwt\JwtService;
use Vortos\Auth\Storage\InMemoryTokenStorage;

/**
 * A session is bound to the credentials it was opened under.
 *
 * Changing a password or a second factor has to end every other session, and the token store
 * alone cannot promise that: a refresh racing the change, a grace-window re-presentation, or a
 * store write that failed would each leave a session alive under the old credentials. Binding
 * puts the answer in the token itself, checked against the account as it is now.
 */
final class JwtSessionBindingTest extends TestCase
{
    private const SECRET    = 'binding-secret-eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';
    private const ATTRIBUTE = 'credential_stamp';

    public function test_the_refresh_token_carries_the_binding_and_the_access_token_does_not(): void
    {
        $service = $this->service(new InMemoryTokenStorage());

        $token = $service->issue($this->identity('stamp-1'));

        self::assertSame('stamp-1', $this->payload($token->refreshToken)['bnd'] ?? null);
        $access = $this->payload($token->accessToken);
        self::assertArrayNotHasKey(self::ATTRIBUTE, (array) ($access['attrs'] ?? []));
        self::assertArrayNotHasKey('bnd', $access);
        self::assertSame('org-1', ((array) $access['attrs'])['org_id'] ?? null, 'the other attributes still travel');
    }

    public function test_an_unchanged_binding_refreshes_and_the_successor_stays_bound(): void
    {
        $service = $this->service(new InMemoryTokenStorage());

        $first  = $service->issue($this->identity('stamp-1'));
        $second = $service->refresh($first->refreshToken, $this->identity('stamp-1'));

        self::assertSame('stamp-1', $this->payload($second->refreshToken)['bnd'] ?? null);
        $service->refresh($second->refreshToken, $this->identity('stamp-1'));
        $this->addToAssertionCount(1);
    }

    public function test_a_changed_binding_refuses_the_refresh_as_a_revoked_session(): void
    {
        $storage = new InMemoryTokenStorage();
        $service = $this->service($storage);

        $other = $service->issue($this->identity('stamp-1'));

        try {
            $service->refresh($other->refreshToken, $this->identity('stamp-2'));
            self::fail('A session opened under the old credentials refreshed under the new ones.');
        } catch (TokenRevokedException) {
        }

        // The refused token is tombstoned, not left live: presenting it again, even after the
        // binding somehow matched, is a revoked session and never a rotation.
        $this->expectException(TokenRevokedException::class);
        $service->refresh($other->refreshToken, $this->identity('stamp-1'));
    }

    public function test_refusing_a_stale_session_leaves_the_sessions_opened_under_the_new_binding_alone(): void
    {
        $service = $this->service(new InMemoryTokenStorage());

        $stale   = $service->issue($this->identity('stamp-1'));
        $renewed = $service->issue($this->identity('stamp-2'));

        try {
            $service->refresh($stale->refreshToken, $this->identity('stamp-2'));
            self::fail('Expected the stale session to be refused.');
        } catch (TokenRevokedException) {
        }

        // Refused as revoked, not as reuse: the theft response would have ended this one too.
        $service->refresh($renewed->refreshToken, $this->identity('stamp-2'));
        $this->addToAssertionCount(1);
    }

    public function test_a_token_minted_before_binding_was_switched_on_is_refused(): void
    {
        $storage = new InMemoryTokenStorage();
        $legacy  = (new JwtService(JwtConfig::fromSecret(self::SECRET), $storage))->issue($this->identity('stamp-1'));

        self::assertArrayNotHasKey('bnd', $this->payload($legacy->refreshToken));

        $this->expectException(TokenRevokedException::class);
        $this->service($storage)->refresh($legacy->refreshToken, $this->identity('stamp-1'));
    }

    public function test_an_identity_without_the_attribute_fails_at_the_mint_site(): void
    {
        $service = $this->service(new InMemoryTokenStorage());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('credential_stamp');
        $service->issue(new UserIdentity('user-1', ['ROLE_USER'], ['org_id' => 'org-1']));
    }

    public function test_refreshing_with_an_identity_without_the_attribute_fails_loudly(): void
    {
        $service = $this->service(new InMemoryTokenStorage());
        $token   = $service->issue($this->identity('stamp-1'));

        $this->expectException(\LogicException::class);
        $service->refresh($token->refreshToken, new UserIdentity('user-1', ['ROLE_USER']));
    }

    public function test_without_binding_nothing_changes(): void
    {
        $service = new JwtService(JwtConfig::fromSecret(self::SECRET), new InMemoryTokenStorage());

        $token = $service->issue(new UserIdentity('user-1', ['ROLE_USER']));
        self::assertArrayNotHasKey('bnd', $this->payload($token->refreshToken));
        $service->refresh($token->refreshToken, new UserIdentity('user-1', ['ROLE_USER']));
        $this->addToAssertionCount(1);
    }

    public function test_an_empty_attribute_name_is_refused_in_config(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new VortosAuthConfig())->bindSessionsToAttribute(' ');
    }

    public function test_an_empty_attribute_name_is_refused_by_the_jwt_config(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        JwtConfig::fromSecret(self::SECRET, sessionBindingAttribute: '');
    }

    private function service(InMemoryTokenStorage $storage): JwtService
    {
        return new JwtService(JwtConfig::fromSecret(self::SECRET, sessionBindingAttribute: self::ATTRIBUTE), $storage);
    }

    private function identity(string $stamp): UserIdentity
    {
        return new UserIdentity('user-1', ['ROLE_USER'], ['org_id' => 'org-1', self::ATTRIBUTE => $stamp]);
    }

    /** @return array<string, mixed> */
    private function payload(string $jwt): array
    {
        $part = explode('.', $jwt)[1];

        return (array) json_decode((string) base64_decode(strtr($part, '-_', '+/') . str_repeat('=', (4 - strlen($part) % 4) % 4)), true);
    }
}
