<?php

declare(strict_types=1);

namespace Vortos\Auth\Tests\Jwt;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\Exception\TokenReusedException;
use Vortos\Auth\Exception\TokenRevokedException;
use Vortos\Auth\Identity\UserIdentity;
use Vortos\Auth\Jwt\JwtConfig;
use Vortos\Auth\Jwt\JwtService;
use Vortos\Auth\Jwt\Key\KeyStatus;
use Vortos\Auth\Jwt\Key\Keyring;
use Vortos\Auth\Jwt\Key\SigningKey;
use Vortos\Auth\Storage\InMemoryTokenStorage;

/**
 * Regression: a deliberately revoked refresh token must be distinguishable from a reused one.
 *
 * The historical bug: revoke() merely deleted the token, so when the revoked device later fired
 * its own refresh, refresh() saw an already-gone token, could not tell it from a replay, and ran
 * the RFC 6819 theft response — revoking EVERY session the user had. Result: revoking one device
 * logged the user out on all of them. These tests pin the corrected behaviour.
 */
final class JwtRefreshRevokeTest extends TestCase
{
    private const SECRET = 'revoke-secret-dddddddddddddddddddddddddddddddddddddddddddddddddddddddd';

    public function test_revoked_token_refresh_reports_revoked_and_spares_other_sessions(): void
    {
        $storage  = new InMemoryTokenStorage();
        $service  = $this->serviceWith($storage);
        $identity = new UserIdentity('user-1', ['ROLE_USER']);

        // Two devices for the same user.
        $deviceA = $service->issue($identity);
        $deviceB = $service->issue($identity);

        // User revokes device A from the session list (single-device sign-out).
        $storage->revoke($this->jti($deviceA->refreshToken));

        // Device A, unaware, fires one last refresh → cleanly rejected as Revoked, no theft nuke.
        try {
            $service->refresh($deviceA->refreshToken, $identity);
            $this->fail('Expected TokenRevokedException for a deliberately revoked token.');
        } catch (TokenRevokedException) {
            // expected
        }

        // Device B must still be alive — revoking A must not touch it.
        $refreshedB = $service->refresh($deviceB->refreshToken, $identity);
        $this->assertNotSame('', $refreshedB->accessToken);
    }

    public function test_genuine_reuse_still_triggers_the_theft_nuke(): void
    {
        $storage  = new InMemoryTokenStorage();
        $service  = $this->serviceWith($storage);
        $identity = new UserIdentity('user-1', ['ROLE_USER']);

        $deviceA = $service->issue($identity);
        $deviceB = $service->issue($identity);

        // Device A rotates normally (token now consumed, NOT revoked).
        $service->refresh($deviceA->refreshToken, $identity);

        // Re-presenting the consumed original is genuine reuse → theft response.
        try {
            $service->refresh($deviceA->refreshToken, $identity);
            $this->fail('Expected TokenReusedException for a replayed token.');
        } catch (TokenReusedException) {
            // expected
        }

        // Theft response revokes everything — device B is now dead too. Because the nuke leaves
        // tombstones, B sees a clean Revoked rather than a second theft alarm.
        $this->expectException(TokenRevokedException::class);
        $service->refresh($deviceB->refreshToken, $identity);
    }

    private function serviceWith(InMemoryTokenStorage $storage): JwtService
    {
        return new JwtService(
            new JwtConfig(
                new Keyring(SigningKey::hs256('key-1', self::SECRET, KeyStatus::Active)),
                issuer: 'test',
                audience: 'test',
            ),
            $storage,
        );
    }

    private function jti(string $refreshToken): string
    {
        $parts = explode('.', $refreshToken);
        $payload = (array) json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);

        return (string) $payload['jti'];
    }
}
