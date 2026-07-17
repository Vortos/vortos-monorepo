<?php

declare(strict_types=1);

namespace Vortos\Auth\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Vortos\Auth\Identity\UserIdentity;
use Vortos\Auth\Jwt\JwtConfig;
use Vortos\Auth\Jwt\JwtService;
use Vortos\Auth\Middleware\AuthMiddleware;
use Vortos\Auth\Session\Contract\SessionStoreInterface;
use Vortos\Auth\Session\SessionEnforcementResult;
use Vortos\Auth\Session\SessionLivenessGuard;
use Vortos\Auth\Storage\InMemoryTokenStorage;
use Vortos\Cache\Adapter\ArrayAdapter;
use Vortos\Http\Request;

/** Local protected-controller stub so this test does not depend on another test file's classes. */
final class StubLivenessController
{
    public function __invoke(): Response
    {
        return new Response('ok', 200);
    }
}

/**
 * Session-liveness enforcement: an access token whose session was revoked must be rejected
 * on the very next request, not left usable until it expires.
 *
 * This closes the window where a revoked device kept working (and could still navigate the
 * app) for up to the access-token TTL because access tokens were validated statelessly.
 */
final class AuthMiddlewareSessionLivenessTest extends TestCase
{
    private JwtService $jwtService;
    private ArrayAdapter $arrayAdapter;
    private InMemoryTokenStorage $tokenStorage;

    protected function setUp(): void
    {
        $config = JwtConfig::fromSecret(
            secret: 'test-secret-for-unit-tests-only-not-for-production-xxxxxxxxxxxxx',
            accessTokenTtl: 900,
            refreshTokenTtl: 604800,
            issuer: 'test',
            audience: 'test',
        );
        $this->tokenStorage = new InMemoryTokenStorage();
        $this->jwtService = new JwtService($config, $this->tokenStorage);
        $this->arrayAdapter = new ArrayAdapter();
    }

    protected function tearDown(): void
    {
        $this->arrayAdapter->clear();
        $this->tokenStorage->clear();
    }

    public function test_live_session_passes(): void
    {
        $store = $this->store(liveSids: ['*']); // every sid is live
        $middleware = $this->middleware($store, enforce: true);

        $response = $middleware->handle($this->authedRequest(), $this->next());

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_revoked_session_is_rejected_immediately(): void
    {
        $store = $this->store(liveSids: []); // no sid is live → session revoked
        $middleware = $this->middleware($store, enforce: true);

        $response = $middleware->handle($this->authedRequest(), $this->next());

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('true', $response->headers->get('X-Session-Revoked'));
    }

    public function test_disabled_by_default_does_not_check_the_store(): void
    {
        $store = $this->store(liveSids: []); // would reject if consulted
        $middleware = $this->middleware($store, enforce: false);

        $response = $middleware->handle($this->authedRequest(), $this->next());

        // Liveness off → stateless behaviour, request passes even though the store says "gone".
        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_store_failure_fails_open(): void
    {
        $store = new class implements SessionStoreInterface {
            public function enforceAndAdd(string $userId, string $jti, int $issuedAt, int $ttl, int $maxSessions, bool $evictOldest, array $meta = []): SessionEnforcementResult { return SessionEnforcementResult::ok(); }
            public function addSession(string $userId, string $jti, int $issuedAt, int $ttl, array $meta = []): void {}
            public function removeSession(string $userId, string $jti): void {}
            public function getSessionCount(string $userId): int { return 0; }
            public function clearAll(string $userId): void {}
            public function listSessions(string $userId): array { return []; }
            public function listSessionsWithMeta(string $userId): array { return []; }
            public function getSessionMeta(string $userId, string $jti): array { return []; }
            public function hasSession(string $userId, string $jti): bool { throw new \RuntimeException('redis down'); }
        };
        $middleware = $this->middleware($store, enforce: true);

        $response = $middleware->handle($this->authedRequest(), $this->next());

        // A store outage must not lock everyone out — fail open.
        $this->assertSame(200, $response->getStatusCode());
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @param list<string> $liveSids '*' matches any sid */
    private function store(array $liveSids): SessionStoreInterface
    {
        return new class($liveSids) implements SessionStoreInterface {
            /** @param list<string> $liveSids */
            public function __construct(private array $liveSids) {}
            public function enforceAndAdd(string $userId, string $jti, int $issuedAt, int $ttl, int $maxSessions, bool $evictOldest, array $meta = []): SessionEnforcementResult { return SessionEnforcementResult::ok(); }
            public function addSession(string $userId, string $jti, int $issuedAt, int $ttl, array $meta = []): void {}
            public function removeSession(string $userId, string $jti): void {}
            public function getSessionCount(string $userId): int { return count($this->liveSids); }
            public function clearAll(string $userId): void {}
            public function listSessions(string $userId): array { return []; }
            public function listSessionsWithMeta(string $userId): array { return []; }
            public function getSessionMeta(string $userId, string $jti): array { return []; }
            public function hasSession(string $userId, string $jti): bool
            {
                return in_array('*', $this->liveSids, true) || in_array($jti, $this->liveSids, true);
            }
        };
    }

    private function middleware(SessionStoreInterface $store, bool $enforce): AuthMiddleware
    {
        // cacheTtl 0 → strict per-request checks, so each test's store double is always consulted.
        $guard = new SessionLivenessGuard($store, positiveCacheTtlSeconds: 0);

        return new AuthMiddleware(
            $this->jwtService,
            $this->arrayAdapter,
            [StubLivenessController::class],
            null,
            $guard,
            $enforce,
        );
    }

    private function authedRequest(): Request
    {
        $token = $this->jwtService->issue(new UserIdentity('user-1', ['ROLE_USER']));
        $request = Request::create('/test');
        $request->attributes->set('_controller', StubLivenessController::class);
        $request->headers->set('Authorization', 'Bearer ' . $token->accessToken);

        return $request;
    }

    private function next(): \Closure
    {
        return fn(Request $r) => new Response('ok', 200);
    }
}
