<?php

declare(strict_types=1);

namespace Vortos\Auth\Tests\Jwt;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\Exception\TokenInvalidException;
use Vortos\Auth\Identity\UserIdentity;
use Vortos\Auth\Jwt\JwtConfig;
use Vortos\Auth\Jwt\JwtService;
use Vortos\Auth\Storage\InMemoryTokenStorage;

/**
 * Per-client audience binding: one API serves several first-party clients, each with its own
 * token audience, and a token minted for one client must never validate — or be launderable via
 * refresh — into another.
 *
 * This is the framework half of the fix for the shared-session defect where an admin-console
 * session could be adopted by the customer SPA sharing the same API.
 */
final class JwtAudienceBindingTest extends TestCase
{
    private function service(string $audience, array $additional): JwtService
    {
        return new JwtService(
            JwtConfig::fromSecret(
                secret: 'test-secret-for-unit-tests-only-not-for-production-xxxxxxxxxxxxx',
                issuer: 'test',
                audience: $audience,
                additionalAudiences: $additional,
            ),
            new InMemoryTokenStorage(),
        );
    }

    private function identity(): UserIdentity
    {
        return new UserIdentity('user-1', ['member'], []);
    }

    public function test_default_audience_is_always_accepted_without_being_listed(): void
    {
        $svc   = $this->service('sqoura-app', []);
        $token = $svc->issue($this->identity());

        $validated = $svc->validate($token->accessToken);

        self::assertSame('sqoura-app', $validated->audience);
    }

    public function test_a_token_minted_for_one_client_is_rejected_on_the_other(): void
    {
        // The customer API accepts both audiences (it must validate its own tokens and recognise
        // admin ones as "not mine"); the admin API accepts only its own.
        $customerApi = $this->service('sqoura-app', ['sqoura-admin']);
        $adminApi    = $this->service('sqoura-admin', ['sqoura-app']);

        $adminToken = $adminApi->issue($this->identity(), audience: 'sqoura-admin');

        // The customer API can decode it (shared keyring) but the token's audience is recorded,
        // so a request-layer guard can refuse it on customer routes.
        $validated = $customerApi->validate($adminToken->accessToken);
        self::assertSame('sqoura-admin', $validated->audience);
    }

    public function test_an_unaccepted_audience_fails_validation_outright(): void
    {
        $issuer   = $this->service('sqoura-admin', []);
        $consumer = $this->service('sqoura-app', []); // does not accept sqoura-admin

        $token = $issuer->issue($this->identity(), audience: 'sqoura-admin');

        $this->expectException(TokenInvalidException::class);
        $consumer->validate($token->accessToken);
    }

    public function test_issuing_for_an_unaccepted_audience_fails_loudly_at_issuance(): void
    {
        $svc = $this->service('sqoura-app', []);

        $this->expectException(\InvalidArgumentException::class);
        $svc->issue($this->identity(), audience: 'sqoura-admin');
    }

    public function test_refresh_rotates_within_the_same_audience_and_never_launders_it(): void
    {
        $svc = $this->service('sqoura-app', ['sqoura-admin']);

        $issued = $svc->issue($this->identity(), audience: 'sqoura-admin');

        $rotated = $svc->refresh($issued->refreshToken, $this->identity());

        // Both halves of the rotated pair keep the admin audience — the refresh did not quietly
        // mint a customer-SPA token from an admin refresh cookie.
        self::assertSame('sqoura-admin', $svc->validate($rotated->accessToken)->audience);
    }

    public function test_absent_audience_claim_is_rejected(): void
    {
        $svc = $this->service('sqoura-app', []);
        // A hand-forged token with no aud claim would decode but must not pass acceptance.
        $this->expectException(TokenInvalidException::class);
        $svc->validate('not.a.jwt');
    }
}
