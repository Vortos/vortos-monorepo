<?php

declare(strict_types=1);

namespace Vortos\Http\Tests;

use PHPUnit\Framework\TestCase;
use Vortos\Http\EventListener\TracingMiddleware;
use Vortos\Http\Request;
use Vortos\Http\Response;
use Vortos\Tracing\Contract\TracingInterface;
use Vortos\Tracing\NoOpSpan;

final class TracingMiddlewareTest extends TestCase
{
    public function test_controller_without_trace_attribute_starts_span_with_method_name(): void
    {
        $tracer = $this->createMock(TracingInterface::class);
        $tracer->expects($this->once())
            ->method('startSpan')
            ->with('http.GET')
            ->willReturn(new NoOpSpan());

        $middleware = new TracingMiddleware($tracer);

        $request = Request::create('/orders', 'GET');
        // Kernel sets _controller_callable before pipeline runs
        $request->attributes->set('_controller_callable', [new PlainController(), 'index']);

        $middleware->handle($request, fn($r) => new Response('ok'));
    }

    public function test_passes_response_through(): void
    {
        $tracer = $this->createMock(TracingInterface::class);
        $tracer->method('startSpan')->willReturn(new NoOpSpan());

        $middleware = new TracingMiddleware($tracer);
        $request = Request::create('/test', 'GET');

        $response = $middleware->handle($request, fn($r) => new Response('body', 201));

        $this->assertSame(201, $response->getStatusCode());
    }

    public function test_exception_is_rethrown_after_span_ends(): void
    {
        $span = $this->createMock(\Vortos\Tracing\Contract\SpanInterface::class);
        $span->expects($this->once())->method('recordException');
        $span->expects($this->once())->method('end');

        $tracer = $this->createMock(TracingInterface::class);
        $tracer->method('startSpan')->willReturn($span);

        $middleware = new TracingMiddleware($tracer);
        $request = Request::create('/test', 'GET');

        $this->expectException(\RuntimeException::class);
        $middleware->handle($request, fn($r) => throw new \RuntimeException('boom'));
    }
}

final class PlainController
{
    public function index(): void {}
}
