<?php

declare(strict_types=1);

namespace Vortos\Auth\Tests\Jwt;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\Contract\UserIdentityInterface;
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
    private function service(): array
    {
        $store  = $this->arrayStore();
        $policy = new class implements SessionPolicyInterface {
            public function getMaxSessions(UserIdentityInterface $identity): int { return 10; }
            public function onLimitExceeded(UserIdentityInterface $identity): SessionLimitAction { return SessionLimitAction::InvalidateOldest; }
        };
        $tokenStorage = new InMemoryTokenStorage();
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
