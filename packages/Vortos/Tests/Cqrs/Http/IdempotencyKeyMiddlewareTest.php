<?php

declare(strict_types=1);

namespace Vortos\Tests\Cqrs\Http;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Vortos\Cqrs\Http\Attribute\RequiresIdempotencyKey;
use Vortos\Cqrs\Http\Middleware\IdempotencyKeyMiddleware;
use Vortos\Http\Request;

#[RequiresIdempotencyKey]
final class StubEnforcedController {}

final class StubUnenforcedController {}

final class IdempotencyKeyMiddlewareTest extends TestCase
{
    private function makeMiddleware(array $enforced = []): IdempotencyKeyMiddleware
    {
        return new IdempotencyKeyMiddleware($enforced);
    }

    private function makeRequest(string $controllerClass, ?string $idempotencyKey = null): Request
    {
        $request = Request::create('/test', 'POST');
        $request->attributes->set('_controller', $controllerClass);

        if ($idempotencyKey !== null) {
            $request->headers->set('Idempotency-Key', $idempotencyKey);
        }

        return $request;
    }

    public function test_passes_when_key_present_on_enforced_controller(): void
    {
        $middleware = $this->makeMiddleware([StubEnforcedController::class]);
        $request    = $this->makeRequest(StubEnforcedController::class, '550e8400-e29b-41d4-a716-446655440000');

        $response = $middleware->handle($request, fn() => new Response('ok', 200));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_returns_422_when_key_absent_on_enforced_controller(): void
    {
        $middleware = $this->makeMiddleware([StubEnforcedController::class]);
        $request    = $this->makeRequest(StubEnforcedController::class);

        $response = $middleware->handle($request, fn() => new Response('ok', 200));

        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        $this->assertStringContainsString('Idempotency-Key', $response->getContent());
    }

    public function test_passes_without_key_on_unenforced_controller(): void
    {
        $middleware = $this->makeMiddleware([StubEnforcedController::class]);
        $request    = $this->makeRequest(StubUnenforcedController::class);

        $response = $middleware->handle($request, fn() => new Response('ok', 200));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_passes_with_no_enforced_controllers_configured(): void
    {
        $middleware = $this->makeMiddleware([]);
        $request    = $this->makeRequest(StubEnforcedController::class);

        $response = $middleware->handle($request, fn() => new Response('ok', 200));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_passes_when_no_controller_on_request(): void
    {
        $middleware = $this->makeMiddleware([StubEnforcedController::class]);
        $request    = Request::create('/test', 'POST');

        $response = $middleware->handle($request, fn() => new Response('ok', 200));

        $this->assertSame(200, $response->getStatusCode());
    }
}
