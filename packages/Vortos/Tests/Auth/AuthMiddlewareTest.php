<?php

declare(strict_types=1);

namespace Tests\Auth;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Vortos\Auth\Attribute\RequiresAuth;
use Vortos\Auth\Identity\AnonymousIdentity;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Auth\Identity\UserIdentity;
use Vortos\Auth\Jwt\JwtConfig;
use Vortos\Auth\Jwt\JwtService;
use Vortos\Auth\Middleware\AuthMiddleware;
use Vortos\Auth\Storage\InMemoryTokenStorage;
use Vortos\Cache\Adapter\ArrayAdapter;

#[RequiresAuth]
final class StubProtectedController
{
    public function __invoke(): Response
    {
        return new Response('protected');
    }
}

final class StubPublicController
{
    public function __invoke(): Response
    {
        return new Response('public');
    }
}

final class AuthMiddlewareTest extends TestCase
{
    private JwtService $jwtService;
    private ArrayAdapter $arrayAdapter;
    private AuthMiddleware $middleware;
    private InMemoryTokenStorage $tokenStorage;
    private HttpKernelInterface $stubKernel;

    protected function setUp(): void
    {
        $config = new JwtConfig(
            secret: 'test-secret-at-least-32-characters-long',
            accessTokenTtl: 900,
            refreshTokenTtl: 604800,
            issuer: 'test',
        );
        $this->tokenStorage = new InMemoryTokenStorage();
        $this->jwtService = new JwtService($config, $this->tokenStorage);
        $this->arrayAdapter = new ArrayAdapter();

        $this->stubKernel = new class implements HttpKernelInterface {
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                return new Response('ok', 200);
            }
        };

        $this->middleware = new AuthMiddleware(
            $this->jwtService,
            $this->arrayAdapter,
            [StubProtectedController::class],
        );
    }

    protected function tearDown(): void
    {
        $this->arrayAdapter->clear();
        $this->tokenStorage->clear();
    }

    /**
     * Creates a RequestEvent with _controller already set in attributes —
     * simulating what RouterListener does in production before our subscriber runs.
     */
    private function makeEvent(string $path, string $controllerClass, array $headers = []): RequestEvent
    {
        $request = Request::create($path);

        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        // Simulate RouterListener having already matched the route
        $request->attributes->set('_controller', $controllerClass);

        return new RequestEvent($this->stubKernel, $request, HttpKernelInterface::MAIN_REQUEST);
    }

    // -------------------------------------------------------------------------
    // PUBLIC ROUTE TESTS
    // -------------------------------------------------------------------------

    public function test_public_route_without_token_does_not_set_response(): void
    {
        $event = $this->makeEvent('/api/public', StubPublicController::class);

        $this->middleware->onKernelRequest($event);

        // No response set — request proceeds to controller
        $this->assertNull($event->getResponse());
    }

    public function test_public_route_sets_anonymous_identity_when_no_token(): void
    {
        $event = $this->makeEvent('/api/public', StubPublicController::class);

        $this->middleware->onKernelRequest($event);

        $provider = new CurrentUserProvider($this->arrayAdapter);
        $this->assertFalse($provider->get()->isAuthenticated());
    }

    public function test_public_route_with_valid_token_does_not_set_response(): void
    {
        $identity = new UserIdentity('user-1', ['ROLE_USER']);
        $token = $this->jwtService->issue($identity);

        $event = $this->makeEvent('/api/public', StubPublicController::class, [
            'Authorization' => 'Bearer ' . $token->accessToken,
        ]);

        $this->middleware->onKernelRequest($event);

        $this->assertNull($event->getResponse());
    }

    public function test_public_route_with_valid_token_sets_user_identity(): void
    {
        $identity = new UserIdentity('user-1', ['ROLE_USER']);
        $token = $this->jwtService->issue($identity);

        $event = $this->makeEvent('/api/public', StubPublicController::class, [
            'Authorization' => 'Bearer ' . $token->accessToken,
        ]);

        $this->middleware->onKernelRequest($event);

        $provider = new CurrentUserProvider($this->arrayAdapter);
        $resolved = $provider->get();
        $this->assertTrue($resolved->isAuthenticated());
        $this->assertEquals('user-1', $resolved->id());
    }

    // -------------------------------------------------------------------------
    // PROTECTED ROUTE — NO TOKEN
    // -------------------------------------------------------------------------

    public function test_protected_route_without_token_sets_401_response(): void
    {
        $event = $this->makeEvent('/api/protected', StubProtectedController::class);

        $this->middleware->onKernelRequest($event);

        $this->assertNotNull($event->getResponse());
        $this->assertEquals(401, $event->getResponse()->getStatusCode());
    }

    public function test_protected_route_401_response_is_json(): void
    {
        $event = $this->makeEvent('/api/protected', StubProtectedController::class);

        $this->middleware->onKernelRequest($event);

        $body = json_decode($event->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $body);
        $this->assertEquals('Unauthorized', $body['error']);
        $this->assertArrayHasKey('message', $body);
    }

    // -------------------------------------------------------------------------
    // PROTECTED ROUTE — VALID TOKEN
    // -------------------------------------------------------------------------

    public function test_protected_route_with_valid_token_does_not_set_response(): void
    {
        $identity = new UserIdentity('user-1', ['ROLE_USER']);
        $token = $this->jwtService->issue($identity);

        $event = $this->makeEvent('/api/protected', StubProtectedController::class, [
            'Authorization' => 'Bearer ' . $token->accessToken,
        ]);

        $this->middleware->onKernelRequest($event);

        $this->assertNull($event->getResponse());
    }

    public function test_protected_route_with_valid_token_sets_identity(): void
    {
        $identity = new UserIdentity('user-42', ['ROLE_ADMIN']);
        $token = $this->jwtService->issue($identity);

        $event = $this->makeEvent('/api/protected', StubProtectedController::class, [
            'Authorization' => 'Bearer ' . $token->accessToken,
        ]);

        $this->middleware->onKernelRequest($event);

        $provider = new CurrentUserProvider($this->arrayAdapter);
        $resolved = $provider->get();
        $this->assertEquals('user-42', $resolved->id());
        $this->assertTrue($resolved->hasRole('ROLE_ADMIN'));
    }

    // -------------------------------------------------------------------------
    // PROTECTED ROUTE — INVALID / MALFORMED TOKENS
    // -------------------------------------------------------------------------

    public function test_protected_route_with_malformed_token_returns_401(): void
    {
        $event = $this->makeEvent('/api/protected', StubProtectedController::class, [
            'Authorization' => 'Bearer not.a.valid.token',
        ]);

        $this->middleware->onKernelRequest($event);

        $this->assertEquals(401, $event->getResponse()->getStatusCode());
    }

    public function test_protected_route_with_truncated_token_returns_401_not_500(): void
    {
        $identity = new UserIdentity('user-1', []);
        $token = $this->jwtService->issue($identity);
        $truncated = substr($token->accessToken, 0, strlen($token->accessToken) - 20);

        $event = $this->makeEvent('/api/protected', StubProtectedController::class, [
            'Authorization' => 'Bearer ' . $truncated,
        ]);

        $this->middleware->onKernelRequest($event);

        // This was the original bug — truncated token caused 500. Must be 401.
        $this->assertEquals(401, $event->getResponse()->getStatusCode());
    }

    public function test_protected_route_with_tampered_signature_returns_401(): void
    {
        $identity = new UserIdentity('user-1', []);
        $token = $this->jwtService->issue($identity);

        $parts = explode('.', $token->accessToken);
        $parts[2] = 'tampered_signature_here';
        $tampered = implode('.', $parts);

        $event = $this->makeEvent('/api/protected', StubProtectedController::class, [
            'Authorization' => 'Bearer ' . $tampered,
        ]);

        $this->middleware->onKernelRequest($event);

        $this->assertEquals(401, $event->getResponse()->getStatusCode());
    }

    public function test_protected_route_with_expired_token_returns_401(): void
    {
        $config = new JwtConfig(
            secret: 'test-secret-at-least-32-characters-long',
            accessTokenTtl: -1,
            refreshTokenTtl: 604800,
            issuer: 'test',
        );
        $expiredService = new JwtService($config, $this->tokenStorage);
        $identity = new UserIdentity('user-1', []);
        $token = $expiredService->issue($identity);

        $middleware = new AuthMiddleware($expiredService, $this->arrayAdapter, [StubProtectedController::class]);

        $event = $this->makeEvent('/api/protected', StubProtectedController::class, [
            'Authorization' => 'Bearer ' . $token->accessToken,
        ]);

        $middleware->onKernelRequest($event);

        $this->assertEquals(401, $event->getResponse()->getStatusCode());
    }

    public function test_protected_route_with_empty_bearer_returns_401(): void
    {
        $event = $this->makeEvent('/api/protected', StubProtectedController::class, [
            'Authorization' => 'Bearer ',
        ]);

        $this->middleware->onKernelRequest($event);

        $this->assertEquals(401, $event->getResponse()->getStatusCode());
    }

    public function test_protected_route_with_missing_bearer_prefix_returns_401(): void
    {
        $identity = new UserIdentity('user-1', []);
        $token = $this->jwtService->issue($identity);

        $event = $this->makeEvent('/api/protected', StubProtectedController::class, [
            'Authorization' => $token->accessToken, // no 'Bearer ' prefix
        ]);

        $this->middleware->onKernelRequest($event);

        $this->assertEquals(401, $event->getResponse()->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // EDGE CASES
    // -------------------------------------------------------------------------

    public function test_subrequest_is_ignored(): void
    {
        $request = Request::create('/api/protected');
        $request->attributes->set('_controller', StubProtectedController::class);

        // Subrequest — not MAIN_REQUEST
        $event = new RequestEvent(
            $this->stubKernel,
            $request,
            HttpKernelInterface::SUB_REQUEST,
        );

        $this->middleware->onKernelRequest($event);

        // Subrequests are skipped entirely — no response set, no identity change
        $this->assertNull($event->getResponse());
    }

    public function test_route_with_no_controller_does_not_set_response(): void
    {
        $request = Request::create('/some/unmatched/path');
        // No _controller set — simulates unmatched route

        $event = new RequestEvent($this->stubKernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $this->middleware->onKernelRequest($event);

        // Should not 401 — inner kernel handles the 404
        $this->assertNull($event->getResponse());
    }
}
