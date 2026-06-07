<?php

declare(strict_types=1);

namespace Vortos\Auth\Tests;

use PHPUnit\Framework\TestCase;
use Vortos\Http\Request;
use Vortos\Http\Response;
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

    protected function setUp(): void
    {
        $config = new JwtConfig(
            secret: 'test-secret-for-unit-tests-only-not-for-production-xxxxxxxxxxxxx',
            accessTokenTtl: 900,
            refreshTokenTtl: 604800,
            issuer: 'test',
        );
        $this->tokenStorage = new InMemoryTokenStorage();
        $this->jwtService = new JwtService($config, $this->tokenStorage);
        $this->arrayAdapter = new ArrayAdapter();

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

    private function makeRequest(string $controllerClass, array $headers = []): Request
    {
        $request = Request::create('/test');
        $request->attributes->set('_controller', $controllerClass);
        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }
        return $request;
    }

    private function next(): \Closure
    {
        return fn(Request $r) => new Response('ok', 200);
    }

    // -------------------------------------------------------------------------
    // PUBLIC ROUTE TESTS
    // -------------------------------------------------------------------------

    public function test_public_route_without_token_passes_through(): void
    {
        $request = $this->makeRequest(StubPublicController::class);
        $response = $this->middleware->handle($request, $this->next());
        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_public_route_sets_anonymous_identity_when_no_token(): void
    {
        $request = $this->makeRequest(StubPublicController::class);
        $this->middleware->handle($request, $this->next());

        $provider = new CurrentUserProvider($this->arrayAdapter);
        $this->assertFalse($provider->get()->isAuthenticated());
    }

    public function test_public_route_with_valid_token_passes_through(): void
    {
        $identity = new UserIdentity('user-1', ['ROLE_USER']);
        $token = $this->jwtService->issue($identity);

        $request = $this->makeRequest(StubPublicController::class, [
            'Authorization' => 'Bearer ' . $token->accessToken,
        ]);

        $response = $this->middleware->handle($request, $this->next());
        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_public_route_with_valid_token_sets_user_identity(): void
    {
        $identity = new UserIdentity('user-1', ['ROLE_USER']);
        $token = $this->jwtService->issue($identity);

        $request = $this->makeRequest(StubPublicController::class, [
            'Authorization' => 'Bearer ' . $token->accessToken,
        ]);

        $this->middleware->handle($request, $this->next());

        $provider = new CurrentUserProvider($this->arrayAdapter);
        $resolved = $provider->get();
        $this->assertTrue($resolved->isAuthenticated());
        $this->assertEquals('user-1', $resolved->id());
    }

    // -------------------------------------------------------------------------
    // PROTECTED ROUTE — NO TOKEN
    // -------------------------------------------------------------------------

    public function test_protected_route_without_token_returns_401(): void
    {
        $request = $this->makeRequest(StubProtectedController::class);
        $response = $this->middleware->handle($request, $this->next());
        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_protected_route_401_response_is_json(): void
    {
        $request = $this->makeRequest(StubProtectedController::class);
        $response = $this->middleware->handle($request, $this->next());
        $body = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('error', $body);
        $this->assertEquals('Unauthorized', $body['error']);
        $this->assertArrayHasKey('message', $body);
    }

    // -------------------------------------------------------------------------
    // PROTECTED ROUTE — VALID TOKEN
    // -------------------------------------------------------------------------

    public function test_protected_route_with_valid_token_passes_through(): void
    {
        $identity = new UserIdentity('user-1', ['ROLE_USER']);
        $token = $this->jwtService->issue($identity);

        $request = $this->makeRequest(StubProtectedController::class, [
            'Authorization' => 'Bearer ' . $token->accessToken,
        ]);

        $response = $this->middleware->handle($request, $this->next());
        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_protected_route_with_valid_token_sets_identity(): void
    {
        $identity = new UserIdentity('user-42', ['ROLE_ADMIN']);
        $token = $this->jwtService->issue($identity);

        $request = $this->makeRequest(StubProtectedController::class, [
            'Authorization' => 'Bearer ' . $token->accessToken,
        ]);

        $this->middleware->handle($request, $this->next());

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
        $request = $this->makeRequest(StubProtectedController::class, [
            'Authorization' => 'Bearer not.a.valid.token',
        ]);

        $response = $this->middleware->handle($request, $this->next());
        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_protected_route_with_truncated_token_returns_401_not_500(): void
    {
        $identity = new UserIdentity('user-1', []);
        $token = $this->jwtService->issue($identity);
        $truncated = substr($token->accessToken, 0, strlen($token->accessToken) - 20);

        $request = $this->makeRequest(StubProtectedController::class, [
            'Authorization' => 'Bearer ' . $truncated,
        ]);

        $response = $this->middleware->handle($request, $this->next());
        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_protected_route_with_tampered_signature_returns_401(): void
    {
        $identity = new UserIdentity('user-1', []);
        $token = $this->jwtService->issue($identity);

        $parts = explode('.', $token->accessToken);
        $parts[2] = 'tampered_signature_here';
        $tampered = implode('.', $parts);

        $request = $this->makeRequest(StubProtectedController::class, [
            'Authorization' => 'Bearer ' . $tampered,
        ]);

        $response = $this->middleware->handle($request, $this->next());
        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_protected_route_with_expired_token_returns_401(): void
    {
        $config = new JwtConfig(
            secret: 'test-secret-for-unit-tests-only-not-for-production-xxxxxxxxxxxxx',
            accessTokenTtl: -1,
            refreshTokenTtl: 604800,
            issuer: 'test',
        );
        $expiredService = new JwtService($config, $this->tokenStorage);
        $identity = new UserIdentity('user-1', []);
        $token = $expiredService->issue($identity);

        $middleware = new AuthMiddleware($expiredService, $this->arrayAdapter, [StubProtectedController::class]);

        $request = $this->makeRequest(StubProtectedController::class, [
            'Authorization' => 'Bearer ' . $token->accessToken,
        ]);

        $response = $middleware->handle($request, $this->next());
        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_protected_route_with_empty_bearer_returns_401(): void
    {
        $request = $this->makeRequest(StubProtectedController::class, [
            'Authorization' => 'Bearer ',
        ]);

        $response = $this->middleware->handle($request, $this->next());
        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_protected_route_with_missing_bearer_prefix_returns_401(): void
    {
        $identity = new UserIdentity('user-1', []);
        $token = $this->jwtService->issue($identity);

        $request = $this->makeRequest(StubProtectedController::class, [
            'Authorization' => $token->accessToken, // no 'Bearer ' prefix
        ]);

        $response = $this->middleware->handle($request, $this->next());
        $this->assertSame(401, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // EDGE CASES
    // -------------------------------------------------------------------------

    public function test_route_with_no_controller_does_not_block(): void
    {
        $request = Request::create('/some/unmatched/path');
        // No _controller set — simulates unmatched route

        $response = $this->middleware->handle($request, $this->next());

        // Should not 401 — passes through
        $this->assertSame(200, $response->getStatusCode());
    }
}
