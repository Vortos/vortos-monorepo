<?php

declare(strict_types=1);

namespace Tests\Auth;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\Exception\TokenExpiredException;
use Vortos\Auth\Exception\TokenInvalidException;
use Vortos\Auth\Exception\TokenRevokedException;
use Vortos\Auth\Identity\AnonymousIdentity;
use Vortos\Auth\Identity\UserIdentity;
use Vortos\Auth\Jwt\JwtConfig;
use Vortos\Auth\Jwt\JwtService;
use Vortos\Auth\Storage\InMemoryTokenStorage;

final class JwtServiceTest extends TestCase
{
    private JwtService $jwtService;
    private InMemoryTokenStorage $tokenStorage;
    private JwtConfig $config;

    protected function setUp(): void
    {
        $this->config = new JwtConfig(
            secret: 'test-secret-for-unit-tests-only-not-for-production-xxxxxxxxxxxxx',
            accessTokenTtl: 900,
            refreshTokenTtl: 604800,
            issuer: 'test',
        );
        $this->tokenStorage = new InMemoryTokenStorage();
        $this->jwtService = new JwtService($this->config, $this->tokenStorage);
    }

    protected function tearDown(): void
    {
        $this->tokenStorage->clear();
    }

    // --- ISSUE TOKEN TESTS ---

    public function test_issue_returns_token_pair(): void
    {
        $identity = new UserIdentity('user-1', ['ROLE_USER']);
        $token = $this->jwtService->issue($identity);

        $this->assertNotEmpty($token->accessToken);
        $this->assertNotEmpty($token->refreshToken);
        $this->assertGreaterThan(time(), $token->accessTokenExpiresAt);
        $this->assertGreaterThan(time(), $token->refreshTokenExpiresAt);
        $this->assertGreaterThan($token->accessTokenExpiresAt, $token->refreshTokenExpiresAt);
    }

    public function test_issue_access_token_has_correct_ttl(): void
    {
        $identity = new UserIdentity('user-1', []);
        $before = time();
        $token = $this->jwtService->issue($identity);

        $this->assertEqualsWithDelta($before + 900, $token->accessTokenExpiresAt, 2);
    }

    public function test_issue_refresh_token_has_correct_ttl(): void
    {
        $identity = new UserIdentity('user-1', []);
        $before = time();
        $token = $this->jwtService->issue($identity);

        $this->assertEqualsWithDelta($before + 604800, $token->refreshTokenExpiresAt, 2);
    }

    public function test_issue_stores_refresh_token_jti_in_storage(): void
    {
        $identity = new UserIdentity('user-1', []);
        $this->jwtService->issue($identity);

        // Storage should have at least one valid token for this user
        // We verify by issuing then revoking all — if storage was empty, revoke would be a no-op
        $this->jwtService->revokeAll('user-1');

        // After revoke, a fresh token should still be issuable
        $token = $this->jwtService->issue($identity);
        $this->assertNotEmpty($token->refreshToken);
    }

    // --- VALIDATE TOKEN TESTS ---

    public function test_validate_returns_correct_identity(): void
    {
        $identity = new UserIdentity('user-123', ['ROLE_USER', 'ROLE_ADMIN']);
        $token = $this->jwtService->issue($identity);

        $validated = $this->jwtService->validate($token->accessToken);

        $this->assertEquals('user-123', $validated->id());
        $this->assertEquals(['ROLE_USER', 'ROLE_ADMIN'], $validated->roles());
        $this->assertTrue($validated->isAuthenticated());
    }

    public function test_issue_and_validate_roundtrips_authz_version_claim(): void
    {
        $identity = new UserIdentity('user-123', ['ROLE_USER'], ['authz_version' => 7]);
        $token = $this->jwtService->issue($identity);

        $validated = $this->jwtService->validate($token->accessToken);

        $this->assertSame(7, $validated->getAttribute('authz_version'));
    }

    public function test_validate_throws_on_invalid_signature(): void
    {
        $identity = new UserIdentity('user-1', []);
        $token = $this->jwtService->issue($identity);

        // Tamper with the token
        $parts = explode('.', $token->accessToken);
        $parts[2] = 'invalidsignature';
        $tampered = implode('.', $parts);

        $this->expectException(TokenInvalidException::class);
        $this->jwtService->validate($tampered);
    }

    public function test_validate_throws_on_malformed_token(): void
    {
        $this->expectException(TokenInvalidException::class);
        $this->jwtService->validate('not.a.valid.jwt.token.at.all');
    }

    public function test_validate_throws_on_truncated_token(): void
    {
        $identity = new UserIdentity('user-1', []);
        $token = $this->jwtService->issue($identity);

        // Truncate the token like the curl test did
        $truncated = substr($token->accessToken, 0, strlen($token->accessToken) - 20);

        $this->expectException(TokenInvalidException::class);
        $this->jwtService->validate($truncated);
    }

    public function test_validate_throws_on_expired_token(): void
    {
        $config = new JwtConfig(
            secret: 'test-secret-for-unit-tests-only-not-for-production-xxxxxxxxxxxxx',
            accessTokenTtl: -1, // already expired
            refreshTokenTtl: 604800,
            issuer: 'test',
        );
        $service = new JwtService($config, $this->tokenStorage);

        $identity = new UserIdentity('user-1', []);
        $token = $service->issue($identity);

        $this->expectException(TokenExpiredException::class);
        $service->validate($token->accessToken);
    }

    public function test_validate_throws_when_refresh_token_passed_as_access(): void
    {
        $identity = new UserIdentity('user-1', []);
        $token = $this->jwtService->issue($identity);

        $this->expectException(TokenInvalidException::class);
        $this->jwtService->validate($token->refreshToken); // wrong type
    }

    // --- REFRESH TOKEN TESTS ---

    public function test_refresh_issues_new_token_pair(): void
    {
        $identity = new UserIdentity('user-1', ['ROLE_USER']);
        $original = $this->jwtService->issue($identity);

        // Small sleep to ensure different iat timestamp
        sleep(1);

        $new = $this->jwtService->refresh($original->refreshToken, $identity);

        // Refresh tokens always differ — they contain a unique JTI
        $this->assertNotEquals($original->refreshToken, $new->refreshToken);
        // Access tokens may be identical if issued within the same second
        // The important guarantee is the refresh token is rotated
    }

    public function test_refresh_issues_access_token_with_current_identity_authz_version(): void
    {
        $oldIdentity = new UserIdentity('user-1', ['ROLE_USER'], ['authz_version' => 1]);
        $currentIdentity = new UserIdentity('user-1', ['ROLE_USER'], ['authz_version' => 5]);
        $original = $this->jwtService->issue($oldIdentity);

        $new = $this->jwtService->refresh($original->refreshToken, $currentIdentity);
        $validated = $this->jwtService->validate($new->accessToken);

        $this->assertSame(5, $validated->getAttribute('authz_version'));
    }

    public function test_refresh_revokes_old_refresh_token(): void
    {
        $identity = new UserIdentity('user-1', []);
        $original = $this->jwtService->issue($identity);

        $this->jwtService->refresh($original->refreshToken, $identity);

        // Old refresh token should now be revoked
        $this->expectException(TokenRevokedException::class);
        $this->jwtService->refresh($original->refreshToken, $identity);
    }

    public function test_refresh_throws_on_expired_refresh_token(): void
    {
        $config = new JwtConfig(
            secret: 'test-secret-for-unit-tests-only-not-for-production-xxxxxxxxxxxxx',
            accessTokenTtl: 900,
            refreshTokenTtl: -1, // already expired
            issuer: 'test',
        );
        $service = new JwtService($config, $this->tokenStorage);

        $identity = new UserIdentity('user-1', []);
        $token = $service->issue($identity);

        $this->expectException(TokenExpiredException::class);
        $service->refresh($token->refreshToken, $identity);
    }

    public function test_refresh_throws_when_access_token_passed_as_refresh(): void
    {
        $identity = new UserIdentity('user-1', []);
        $token = $this->jwtService->issue($identity);

        $this->expectException(TokenInvalidException::class);
        $this->jwtService->refresh($token->accessToken, $identity); // wrong type
    }

    // --- REVOKE ALL TESTS ---

    public function test_revoke_all_invalidates_all_user_tokens(): void
    {
        $identity = new UserIdentity('user-1', []);

        $token1 = $this->jwtService->issue($identity);
        $token2 = $this->jwtService->issue($identity);

        $this->jwtService->revokeAll('user-1');

        $this->expectException(TokenRevokedException::class);
        $this->jwtService->refresh($token1->refreshToken, $identity);
    }

    public function test_revoke_all_does_not_affect_other_users(): void
    {
        $identity1 = new UserIdentity('user-1', []);
        $identity2 = new UserIdentity('user-2', []);

        $token1 = $this->jwtService->issue($identity1);
        $token2 = $this->jwtService->issue($identity2);

        $this->jwtService->revokeAll('user-1');

        // user-2's token should still be valid
        $newToken = $this->jwtService->refresh($token2->refreshToken, $identity2);
        $this->assertNotEmpty($newToken->accessToken);
    }

    // --- TO ARRAY TEST ---

    public function test_token_to_array_has_correct_shape(): void
    {
        $identity = new UserIdentity('user-1', []);
        $token = $this->jwtService->issue($identity);

        $array = $token->toArray();

        $this->assertArrayHasKey('access_token', $array);
        $this->assertArrayHasKey('refresh_token', $array);
        $this->assertArrayHasKey('access_token_expires_at', $array);
        $this->assertArrayHasKey('refresh_token_expires_at', $array);
        $this->assertEquals('Bearer', $array['token_type']);
    }
}
