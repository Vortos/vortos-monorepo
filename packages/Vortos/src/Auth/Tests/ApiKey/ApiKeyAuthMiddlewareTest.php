<?php

declare(strict_types=1);

namespace Vortos\Auth\Tests\ApiKey;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\ApiKey\ApiKeyRecord;
use Vortos\Auth\ApiKey\ApiKeyService;
use Vortos\Auth\ApiKey\Middleware\ApiKeyAuthMiddleware;
use Vortos\Auth\ApiKey\Storage\ApiKeyStorageInterface;
use Vortos\Http\Request;
use Vortos\Http\Response;

final class ApiKeyAuthMiddlewareTest extends TestCase
{
    private const RAW_KEY = 'vrtk_test-key-abc123';

    private function makeService(?ApiKeyRecord $returnRecord = null): ApiKeyService
    {
        $storage = new class ($returnRecord) implements ApiKeyStorageInterface {
            public function __construct(private readonly ?ApiKeyRecord $record) {}
            public function save(ApiKeyRecord $record): void {}
            public function findByHash(string $hashedKey): ?ApiKeyRecord { return $this->record; }
            public function revoke(string $keyId): void {}
            public function findByUserId(string $userId): array { return []; }
            public function touchLastUsedAt(string $hashedKey, \DateTimeImmutable $at): void {}
        };

        return new ApiKeyService($storage);
    }

    private function validRecord(array $scopes = []): ApiKeyRecord
    {
        return new ApiKeyRecord(
            id:         'key-1',
            userId:     'user-1',
            name:       'test',
            hashedKey:  hash('sha256', self::RAW_KEY),
            scopes:     $scopes,
            active:     true,
            createdAt:  new \DateTimeImmutable(),
            expiresAt:  null,
            lastUsedAt: null,
        );
    }

    private function makeRequest(string $controller, ?string $authHeader = null): Request
    {
        $request = Request::create('/test');
        $request->attributes->set('_controller', $controller);
        if ($authHeader !== null) {
            $request->headers->set('Authorization', $authHeader);
        }
        return $request;
    }

    private function next(): \Closure
    {
        return fn(Request $r) => new Response('ok', 200);
    }

    // ── Pass-through (no attribute) ──

    public function test_passes_through_when_controller_has_no_rule(): void
    {
        $middleware = new ApiKeyAuthMiddleware($this->makeService(), []);
        $response = $middleware->handle(
            $this->makeRequest('App\Controller\PublicController::index'),
            $this->next(),
        );
        $this->assertSame(200, $response->getStatusCode());
    }

    // ── Method-level attribute ──

    public function test_rejects_method_level_without_key(): void
    {
        $routeMap = ['App\Ctrl::action' => ['scopes' => []]];
        $middleware = new ApiKeyAuthMiddleware($this->makeService(), $routeMap);

        $response = $middleware->handle(
            $this->makeRequest('App\Ctrl::action'),
            $this->next(),
        );
        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_allows_method_level_with_valid_key(): void
    {
        $routeMap = ['App\Ctrl::action' => ['scopes' => []]];
        $middleware = new ApiKeyAuthMiddleware(
            $this->makeService($this->validRecord()),
            $routeMap,
        );

        $response = $middleware->handle(
            $this->makeRequest('App\Ctrl::action', 'ApiKey ' . self::RAW_KEY),
            $this->next(),
        );
        $this->assertSame(200, $response->getStatusCode());
    }

    // ── Class-level attribute (the regression) ──

    public function test_rejects_class_level_without_key(): void
    {
        $routeMap = ['App\ExternalSyncController' => ['scopes' => ['sync:write']]];
        $middleware = new ApiKeyAuthMiddleware($this->makeService(), $routeMap);

        $response = $middleware->handle(
            $this->makeRequest('App\ExternalSyncController::push'),
            $this->next(),
        );
        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_allows_class_level_with_valid_key_and_scopes(): void
    {
        $routeMap = ['App\ExternalSyncController' => ['scopes' => ['sync:write']]];
        $middleware = new ApiKeyAuthMiddleware(
            $this->makeService($this->validRecord(['sync:write'])),
            $routeMap,
        );

        $response = $middleware->handle(
            $this->makeRequest('App\ExternalSyncController::push', 'ApiKey ' . self::RAW_KEY),
            $this->next(),
        );
        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_class_level_rejects_insufficient_scopes(): void
    {
        $routeMap = ['App\ExternalSyncController' => ['scopes' => ['sync:write']]];
        $middleware = new ApiKeyAuthMiddleware(
            $this->makeService($this->validRecord(['sync:read'])),
            $routeMap,
        );

        $response = $middleware->handle(
            $this->makeRequest('App\ExternalSyncController::push', 'ApiKey ' . self::RAW_KEY),
            $this->next(),
        );
        $this->assertSame(401, $response->getStatusCode());
    }

    // ── Method-level overrides class-level ──

    public function test_method_level_takes_precedence_over_class_level(): void
    {
        $routeMap = [
            'App\Ctrl'          => ['scopes' => ['admin:write']],
            'App\Ctrl::public'  => ['scopes' => []],
        ];
        $middleware = new ApiKeyAuthMiddleware(
            $this->makeService($this->validRecord([])),
            $routeMap,
        );

        $response = $middleware->handle(
            $this->makeRequest('App\Ctrl::public', 'ApiKey ' . self::RAW_KEY),
            $this->next(),
        );
        $this->assertSame(200, $response->getStatusCode());
    }

    // ── Array controller format ──

    public function test_class_level_works_with_array_controller(): void
    {
        $routeMap = ['App\ExternalSyncController' => ['scopes' => []]];
        $middleware = new ApiKeyAuthMiddleware($this->makeService(), $routeMap);

        $request = Request::create('/test');
        $request->attributes->set('_controller', ['App\ExternalSyncController', 'push']);

        $response = $middleware->handle($request, $this->next());
        $this->assertSame(401, $response->getStatusCode());
    }

    // ── Invalid auth header ──

    public function test_rejects_wrong_auth_scheme(): void
    {
        $routeMap = ['App\Ctrl' => ['scopes' => []]];
        $middleware = new ApiKeyAuthMiddleware($this->makeService(), $routeMap);

        $response = $middleware->handle(
            $this->makeRequest('App\Ctrl::action', 'Bearer some-jwt'),
            $this->next(),
        );
        $this->assertSame(401, $response->getStatusCode());
    }
}
