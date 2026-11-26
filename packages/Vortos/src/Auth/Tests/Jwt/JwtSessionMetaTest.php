<?php

declare(strict_types=1);

namespace Vortos\Auth\Tests\Jwt;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\Contract\UserIdentityInterface;
use Vortos\Auth\Exception\SessionAgeExceededException;
use Vortos\Auth\Exception\TokenRevokedException;
use Vortos\Auth\Identity\UserIdentity;
use Vortos\Auth\Jwt\JwtConfig;
use Vortos\Auth\Jwt\JwtService;
use Vortos\Auth\Jwt\Key\KeyStatus;
use Vortos\Auth\Jwt\Key\Keyring;
use Vortos\Auth\Jwt\Key\SigningKey;
use Vortos\Auth\Session\Contract\SessionPolicyInterface;
use Vortos\Auth\Session\Contract\SessionStoreInterface;
use Vortos\Auth\Session\SessionEnforcementResult;
use Vortos\Auth\Session\SessionEnforcer;
use Vortos\Auth\Session\SessionLimitAction;
use Vortos\Auth\Storage\InMemoryTokenStorage;

/**
 * Proves per-session device metadata is first-class in the framework and survives refresh-token
 * rotation. The historical bug: metadata lived in an app-side side-store keyed by the refresh
 * JTI, and rotation minted a new JTI without carrying it — so one refresh turned a named device
 * into "Unknown device" and reset its sign-in time. Now issue()/refresh() thread metadata through
 * the session store and preserve the original logged-in-at across rotation.
 */
final class JwtSessionMetaTest extends TestCase
{
    private const SECRET = 'meta-secret-eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';

    public function test_metadata_is_stored_on_issue(): void
    {
        [$service, $store] = $this->service();
        $identity = new UserIdentity('user-1', ['ROLE_USER']);

        $token = $service->issue($identity, 0, [
            'user_agent'   => 'Firefox on Linux',
            'ip_address'   => '203.0.113.7',
            'logged_in_at' => 1_700_000_000,
        ]);

        $jti = $this->jti($token->refreshToken);
        $meta = $store->getSessionMeta('user-1', $jti);

        $this->assertSame('Firefox on Linux', $meta['user_agent']);
        $this->assertSame('203.0.113.7', $meta['ip_address']);
        $this->assertSame(1_700_000_000, $meta['logged_in_at']);
    }

    public function test_metadata_carries_across_rotation_with_original_login_time(): void
    {
        [$service, $store] = $this->service();
        $identity = new UserIdentity('user-1', ['ROLE_USER']);

        $original = $service->issue($identity, 0, [
            'user_agent'   => 'Chrome on macOS',
            'ip_address'   => '198.51.100.4',
            'logged_in_at' => 1_700_000_000,
        ]);
        $originalJti = $this->jti($original->refreshToken);

        // Rotate. The new session must inherit the device metadata AND the original sign-in time.
        $rotated    = $service->refresh($original->refreshToken, $identity);
        $rotatedJti = $this->jti($rotated->refreshToken);

        $this->assertNotSame($originalJti, $rotatedJti, 'rotation must mint a new jti');

        // Old session's metadata is gone (session removed); the new one carries it forward.
        $this->assertSame([], $store->getSessionMeta('user-1', $originalJti));

        $meta = $store->getSessionMeta('user-1', $rotatedJti);
        $this->assertSame('Chrome on macOS', $meta['user_agent']);
        $this->assertSame('198.51.100.4', $meta['ip_address']);
        $this->assertSame(1_700_000_000, $meta['logged_in_at'], 'sign-in time must NOT reset on refresh');
    }

    public function test_session_listing_surfaces_metadata(): void
    {
        [$service, $store] = $this->service();
        $identity = new UserIdentity('user-1', ['ROLE_USER']);

        $service->issue($identity, 0, ['user_agent' => 'Safari on iOS', 'logged_in_at' => 42]);

        $listed = $store->listSessionsWithMeta('user-1');
        $this->assertCount(1, $listed);

        $entry = array_values($listed)[0];
        $this->assertSame('Safari on iOS', $entry['meta']['user_agent']);
        $this->assertSame(42, $entry['meta']['logged_in_at']);
    }

    /**
     * @return array{0: JwtService, 1: SessionStoreInterface}
     */
    public function test_a_grace_replay_continues_the_session_it_was_rotated_into(): void
    {
        [$service, $store] = $this->service(graceSeconds: 30);
        $identity = new UserIdentity('user-1', ['ROLE_USER']);

        $original = $service->issue($identity, 0, ['user_agent' => 'Safari on iPad', 'ip_address' => '203.0.113.9', 'logged_in_at' => 1_700_000_000]);
        $tabA     = $service->refresh($original->refreshToken, $identity);
        $tabB     = $service->refresh($original->refreshToken, $identity);

        // The replay is not a session from nothing: it has the device, address and sign-in time.
        $meta = $store->getSessionMeta('user-1', $this->jti($tabB->refreshToken));
        $this->assertSame('Safari on iPad', $meta['user_agent'] ?? null);
        $this->assertSame('203.0.113.9', $meta['ip_address'] ?? null);
        $this->assertSame(1_700_000_000, $meta['logged_in_at'] ?? null);
        $this->assertSame(1_700_000_000, $this->claims($tabB->refreshToken)['auth_time'] ?? null);

        // The session it continued is untouched.
        $service->refresh($tabA->refreshToken, $identity);
        $this->addToAssertionCount(1);
    }

    public function test_a_second_grace_replay_is_an_ended_session_not_another_fork(): void
    {
        [$service] = $this->service(graceSeconds: 30);
        $identity  = new UserIdentity('user-1', ['ROLE_USER']);

        $original = $service->issue($identity, 0, ['logged_in_at' => 1_700_000_000]);
        $tabA     = $service->refresh($original->refreshToken, $identity);
        $service->refresh($original->refreshToken, $identity);

        try {
            $service->refresh($original->refreshToken, $identity);
            $this->fail('A just-rotated token opened a second extra session.');
        } catch (TokenRevokedException) {
        }

        // Ended as revoked, not as theft: the real session carries on.
        $service->refresh($tabA->refreshToken, $identity);
        $this->addToAssertionCount(1);
    }

    public function test_signing_out_everywhere_inside_the_window_leaves_nothing_to_replay(): void
    {
        [$service] = $this->service(graceSeconds: 30);
        $identity  = new UserIdentity('user-1', ['ROLE_USER']);

        $original = $service->issue($identity, 0, ['logged_in_at' => 1_700_000_000]);
        $service->refresh($original->refreshToken, $identity);
        $service->revokeAll('user-1');

        $this->expectException(TokenRevokedException::class);
        $service->refresh($original->refreshToken, $identity);
    }

    public function test_auth_time_is_the_sign_in_and_survives_rotation(): void
    {
        [$service] = $this->service();
        $identity  = new UserIdentity('user-1', ['ROLE_USER']);

        $before = time();
        $fresh  = $service->issue($identity);
        $this->assertGreaterThanOrEqual($before, $this->claims($fresh->refreshToken)['auth_time']);

        $signedIn = $service->issue($identity, 0, ['logged_in_at' => 1_700_000_000]);
        $rotated  = $service->refresh($signedIn->refreshToken, $identity);
        $this->assertSame(1_700_000_000, $this->claims($rotated->refreshToken)['auth_time']);
        $this->assertSame(1_700_000_000, $this->claims($rotated->accessToken)['auth_time']);
        $this->assertSame(1_700_000_000, $service->validate($rotated->accessToken)->authTime);

        // An explicit auth_time (a re-mint of the current session) wins over the meta.
        $remint = $service->issue($identity, 0, ['logged_in_at' => 1_800_000_000], null, 1_700_000_500);
        $this->assertSame(1_700_000_500, $this->claims($remint->refreshToken)['auth_time']);

        // Never in the future.
        $future = $service->issue($identity, 0, [], null, time() + 3600);
        $this->assertLessThanOrEqual(time(), $this->claims($future->refreshToken)['auth_time']);
    }

    public function test_a_session_older_than_the_maximum_age_is_refused_and_removed(): void
    {
        [$service, $store] = $this->service();
        $identity = new UserIdentity('user-1', ['ROLE_USER']);

        $young = $service->issue($identity, 0, ['logged_in_at' => time() - 60]);
        $service->refresh($young->refreshToken, $identity, 0, 4 * 3600);

        $old    = $service->issue($identity, 0, ['logged_in_at' => time() - 5 * 3600]);
        $oldJti = $this->jti($old->refreshToken);
        try {
            $service->refresh($old->refreshToken, $identity, 0, 4 * 3600);
            $this->fail('A five-hour-old session refreshed under a four-hour limit.');
        } catch (SessionAgeExceededException) {
        }
        $this->assertSame([], $store->getSessionMeta('user-1', $oldJti));

        // Refused for good, as an ended session, never rotated.
        $this->expectException(TokenRevokedException::class);
        $service->refresh($old->refreshToken, $identity);
    }

    public function test_the_session_family_survives_rotation_replay_and_re_mint_and_starts_at_sign_in(): void
    {
        [$service] = $this->service(graceSeconds: 30);
        $identity  = new UserIdentity('user-1', ['ROLE_USER']);

        $signIn  = $service->issue($identity, 0, ['logged_in_at' => 1_700_000_000]);
        $family  = $this->claims($signIn->refreshToken)['fam'] ?? null;
        $this->assertIsString($family);
        $this->assertSame($family, $this->claims($signIn->accessToken)['fam'] ?? null);
        $this->assertSame($family, $service->validate($signIn->accessToken)->sessionFamily);

        $rotated = $service->refresh($signIn->refreshToken, $identity);
        $this->assertSame($family, $this->claims($rotated->refreshToken)['fam']);
        $this->assertNotSame($this->jti($signIn->refreshToken), $this->jti($rotated->refreshToken), 'the session id still changes');

        $replayed = $service->refresh($signIn->refreshToken, $identity);
        $this->assertSame($family, $this->claims($replayed->refreshToken)['fam']);

        // A re-mint of the current session passes that session's meta and continues it.
        $remint = $service->issue($identity, 0, ['session_family' => $family]);
        $this->assertSame($family, $this->claims($remint->refreshToken)['fam']);

        // Another sign-in is another family.
        $other = $service->issue($identity);
        $this->assertNotSame($family, $this->claims($other->refreshToken)['fam']);
    }

    public function test_a_session_family_is_live_until_its_sessions_end_however_they_end(): void
    {
        [$service, $store] = $this->service();
        $identity = new UserIdentity('user-1', ['ROLE_USER']);

        $signIn = $service->issue($identity);
        $family = (string) $this->claims($signIn->refreshToken)['fam'];
        $this->assertTrue($service->isSessionFamilyLive('user-1', $family));

        $rotated = $service->refresh($signIn->refreshToken, $identity);
        $this->assertTrue($service->isSessionFamilyLive('user-1', $family), 'a refresh does not end the family');

        // One device revoked from the session list.
        $store->removeSession('user-1', $this->jti($rotated->refreshToken));
        $this->assertFalse($service->isSessionFamilyLive('user-1', $family));

        $again = $service->issue($identity);
        $againFamily = (string) $this->claims($again->refreshToken)['fam'];
        $service->revokeAll('user-1');
        $this->assertFalse($service->isSessionFamilyLive('user-1', $againFamily), 'signed out everywhere');

        $this->assertFalse($service->isSessionFamilyLive('user-1', ''));
        $this->assertFalse($service->isSessionFamilyLive('someone-else', $againFamily));
    }

    public function test_without_session_tracking_no_family_is_ever_live(): void
    {
        $service = new JwtService(
            new JwtConfig(new Keyring(SigningKey::hs256('key-1', self::SECRET, KeyStatus::Active)), issuer: 'test', audience: 'test'),
            new InMemoryTokenStorage(),
        );
        $token = $service->issue(new UserIdentity('user-1', ['ROLE_USER']));

        $this->assertFalse($service->isSessionFamilyLive('user-1', (string) $this->claims($token->refreshToken)['fam']));
    }

    /** @return array<string, mixed> */
    private function claims(string $jwt): array
    {
        $part = explode('.', $jwt)[1];

        return (array) json_decode((string) base64_decode(strtr($part, '-_', '+/') . str_repeat('=', (4 - strlen($part) % 4) % 4)), true);
    }

    private function service(int $graceSeconds = 0): array
    {
        $store  = $this->arrayStore();
        $policy = new class implements SessionPolicyInterface {
            public function getMaxSessions(UserIdentityInterface $identity): int { return 10; }
            public function onLimitExceeded(UserIdentityInterface $identity): SessionLimitAction { return SessionLimitAction::InvalidateOldest; }
        };
        $tokenStorage = new InMemoryTokenStorage(rotationGraceSeconds: $graceSeconds);
        $enforcer = new SessionEnforcer($store, $tokenStorage, $policy);

        $service = new JwtService(
            new JwtConfig(
                new Keyring(SigningKey::hs256('key-1', self::SECRET, KeyStatus::Active)),
                issuer: 'test',
                audience: 'test',
            ),
            $tokenStorage,
            $enforcer,
        );

        return [$service, $store];
    }

    private function arrayStore(): SessionStoreInterface
    {
        return new class implements SessionStoreInterface {
            /** @var array<string, array<string, int>> userId => (jti => issuedAt) */
            private array $sessions = [];
            /** @var array<string, array<string, array<string,mixed>>> userId => (jti => meta) */
            private array $meta = [];

            public function enforceAndAdd(string $userId, string $jti, int $issuedAt, int $ttl, int $maxSessions, bool $evictOldest, array $meta = []): SessionEnforcementResult
            {
                $this->sessions[$userId][$jti] = $issuedAt;
                if ($meta !== []) {
                    $this->meta[$userId][$jti] = $meta;
                }
                return SessionEnforcementResult::ok();
            }
            public function addSession(string $userId, string $jti, int $issuedAt, int $ttl, array $meta = []): void
            {
                $this->sessions[$userId][$jti] = $issuedAt;
                if ($meta !== []) {
                    $this->meta[$userId][$jti] = $meta;
                }
            }
            public function removeSession(string $userId, string $jti): void
            {
                unset($this->sessions[$userId][$jti], $this->meta[$userId][$jti]);
            }
            public function getSessionCount(string $userId): int { return count($this->sessions[$userId] ?? []); }
            public function clearAll(string $userId): void { unset($this->sessions[$userId], $this->meta[$userId]); }
            public function listSessions(string $userId): array { return $this->sessions[$userId] ?? []; }
            public function listSessionsWithMeta(string $userId): array
            {
                $out = [];
                foreach ($this->sessions[$userId] ?? [] as $jti => $issuedAt) {
                    $out[$jti] = ['issued_at' => $issuedAt, 'meta' => $this->meta[$userId][$jti] ?? []];
                }
                return $out;
            }
            public function getSessionMeta(string $userId, string $jti): array { return $this->meta[$userId][$jti] ?? []; }
            public function hasSession(string $userId, string $jti): bool { return isset($this->sessions[$userId][$jti]); }
        };
    }

    private function jti(string $refreshToken): string
    {
        $parts = explode('.', $refreshToken);
        $payload = (array) json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);

        return (string) $payload['jti'];
    }
}
