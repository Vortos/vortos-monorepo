<?php

declare(strict_types=1);

namespace Vortos\Auth\Tests\Jwt;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\Exception\TokenReusedException;
use Vortos\Auth\Identity\UserIdentity;
use Vortos\Auth\Jwt\JwtConfig;
use Vortos\Auth\Jwt\JwtService;
use Vortos\Auth\Jwt\Key\KeyStatus;
use Vortos\Auth\Jwt\Key\Keyring;
use Vortos\Auth\Jwt\Key\SigningKey;
use Vortos\Auth\Storage\InMemoryTokenStorage;

/**
 * End-to-end proof of the refresh-token rotation grace window (VortosAuthConfig::refreshRotationGraceSeconds).
 *
 * Rotation is strict one-time-use: presenting an already-consumed refresh token is treated as
 * theft and revokes every session for the user. That is correct for a genuine leaked token — but
 * it also punishes benign races (two browser tabs refreshing at once, or a refresh request retried
 * after a flaky-network timeout), logging the user out of everything. The grace window absorbs
 * those races without weakening theft detection outside the window.
 */
final class JwtRefreshGraceTest extends TestCase
{
    private const SECRET = 'grace-secret-cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

    public function test_strict_mode_treats_reuse_as_theft(): void
    {
        $service  = $this->serviceWith(new InMemoryTokenStorage()); // grace disabled (default)
        $identity = new UserIdentity('user-1', ['ROLE_USER']);
        $token    = $service->issue($identity);

        // First refresh rotates successfully.
        $service->refresh($token->refreshToken, $identity);

        // Re-presenting the now-consumed token is reuse → theft response.
        $this->expectException(TokenReusedException::class);
        $service->refresh($token->refreshToken, $identity);
    }

    public function test_grace_window_absorbs_a_racing_refresh_without_revoking_sessions(): void
    {
        $service  = $this->serviceWith(new InMemoryTokenStorage(rotationGraceSeconds: 30));
        $identity = new UserIdentity('user-1', ['ROLE_USER']);
        $token    = $service->issue($identity);

        // Tab A refreshes.
        $a = $service->refresh($token->refreshToken, $identity);

        // Tab B refreshes with the same (just-rotated) token a moment later — no theft exception.
        $b = $service->refresh($token->refreshToken, $identity);
        $this->assertNotSame('', $b->accessToken);

        // Crucially, sessions were NOT nuked: the token Tab A received is still valid.
        $c = $service->refresh($a->refreshToken, $identity);
        $this->assertNotSame('', $c->accessToken);
    }

    public function test_grace_does_not_cover_reuse_outside_the_window(): void
    {
        $storage  = new InMemoryTokenStorage(rotationGraceSeconds: 30);
        $service  = $this->serviceWith($storage);
        $identity = new UserIdentity('user-1', ['ROLE_USER']);
        $token    = $service->issue($identity);

        $service->refresh($token->refreshToken, $identity);

        // Age the grace marker past its window — reuse must once again be treated as theft.
        $ref   = new \ReflectionProperty($storage, 'grace');
        $grace = $ref->getValue($storage);
        foreach ($grace as $jti => $entry) {
            $grace[$jti]['expiresAt'] = time() - 1;
        }
        $ref->setValue($storage, $grace);

        $this->expectException(TokenReusedException::class);
        $service->refresh($token->refreshToken, $identity);
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
}
