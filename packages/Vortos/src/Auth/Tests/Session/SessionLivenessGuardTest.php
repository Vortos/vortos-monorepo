<?php

declare(strict_types=1);

namespace Vortos\Auth\Tests\Session;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\Session\Contract\SessionStoreInterface;
use Vortos\Auth\Session\SessionEnforcementResult;
use Vortos\Auth\Session\SessionLivenessGuard;

/**
 * The liveness guard must be correct AND cheap AND resilient: cache positive answers, never
 * cache a revocation, and fail open (without hammering) when the store is down.
 */
final class SessionLivenessGuardTest extends TestCase
{
    public function test_live_session_is_reported_live(): void
    {
        $store = $this->store(live: true);
        $guard = new SessionLivenessGuard($store, positiveCacheTtlSeconds: 5);

        $this->assertTrue($guard->isLive('user-1', 'sid-1'));
    }

    public function test_revoked_session_is_reported_dead(): void
    {
        $store = $this->store(live: false);
        $guard = new SessionLivenessGuard($store, positiveCacheTtlSeconds: 5);

        $this->assertFalse($guard->isLive('user-1', 'sid-1'));
    }

    public function test_positive_result_is_cached_avoiding_repeat_store_calls(): void
    {
        $store = $this->store(live: true);
        $guard = new SessionLivenessGuard($store, positiveCacheTtlSeconds: 60);

        $this->assertTrue($guard->isLive('user-1', 'sid-1'));
        $this->assertTrue($guard->isLive('user-1', 'sid-1'));
        $this->assertTrue($guard->isLive('user-1', 'sid-1'));

        // Three checks, one store hit — the cache absorbed the rest.
        $this->assertSame(1, $store->calls);
    }

    public function test_revocation_is_never_cached(): void
    {
        $store = $this->store(live: false);
        $guard = new SessionLivenessGuard($store, positiveCacheTtlSeconds: 60);

        $guard->isLive('user-1', 'sid-1');
        $guard->isLive('user-1', 'sid-1');

        // A dead session must be re-checked every time — never served stale-live from cache.
        $this->assertSame(2, $store->calls);
    }

    public function test_cache_ttl_zero_checks_every_request(): void
    {
        $store = $this->store(live: true);
        $guard = new SessionLivenessGuard($store, positiveCacheTtlSeconds: 0);

        $guard->isLive('user-1', 'sid-1');
        $guard->isLive('user-1', 'sid-1');

        $this->assertSame(2, $store->calls);
    }

    public function test_store_failure_fails_open(): void
    {
        $store = $this->throwingStore();
        $guard = new SessionLivenessGuard($store, positiveCacheTtlSeconds: 5);

        $this->assertTrue($guard->isLive('user-1', 'sid-1'));
    }

    public function test_circuit_breaker_opens_and_stops_calling_a_dead_store(): void
    {
        $store = $this->throwingStore();
        $guard = new SessionLivenessGuard(
            $store,
            positiveCacheTtlSeconds: 0,
            breakerFailureThreshold: 3,
            breakerResetSeconds: 30,
        );

        // First 3 calls hit the store and fail open; the breaker opens on the 3rd failure.
        for ($i = 0; $i < 3; $i++) {
            $this->assertTrue($guard->isLive('user-1', "sid-$i"));
        }
        $this->assertSame(3, $store->calls);

        // Breaker now open — further calls fail open WITHOUT touching the store.
        $this->assertTrue($guard->isLive('user-1', 'sid-x'));
        $this->assertTrue($guard->isLive('user-1', 'sid-y'));
        $this->assertSame(3, $store->calls, 'breaker must stop hammering the dead store');
    }

    // ── doubles ────────────────────────────────────────────────────────────────

    private function store(bool $live): SessionStoreInterface
    {
        return new class($live) extends CountingSessionStore {
            public function __construct(private bool $live) {}
            public function hasSession(string $userId, string $jti): bool
            {
                ++$this->calls;
                return $this->live;
            }
        };
    }

    private function throwingStore(): SessionStoreInterface
    {
        return new class extends CountingSessionStore {
            public function hasSession(string $userId, string $jti): bool
            {
                ++$this->calls;
                throw new \RuntimeException('store down');
            }
        };
    }
}

/**
 * Base store double that counts hasSession() calls; subclasses define the behaviour.
 */
abstract class CountingSessionStore implements SessionStoreInterface
{
    public int $calls = 0;

    public function enforceAndAdd(string $userId, string $jti, int $issuedAt, int $ttl, int $maxSessions, bool $evictOldest, array $meta = []): SessionEnforcementResult { return SessionEnforcementResult::ok(); }
    public function addSession(string $userId, string $jti, int $issuedAt, int $ttl, array $meta = []): void {}
    public function removeSession(string $userId, string $jti): void {}
    public function getSessionCount(string $userId): int { return 0; }
    public function clearAll(string $userId): void {}
    public function listSessions(string $userId): array { return []; }
    public function listSessionsWithMeta(string $userId): array { return []; }
    public function getSessionMeta(string $userId, string $jti): array { return []; }
    abstract public function hasSession(string $userId, string $jti): bool;
}
