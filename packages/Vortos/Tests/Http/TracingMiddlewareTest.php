<?php

declare(strict_types=1);

namespace Vortos\Tests\Http;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Vortos\Http\EventListener\TracingMiddleware;
use Vortos\Tracing\Contract\TracingInterface;
use Vortos\Tracing\NoOpSpan;

final class TracingMiddlewareTest extends TestCase
{
    public function test_controller_without_trace_attribute_uses_default_span_name(): void
    {
        $tracer = $this->createMock(TracingInterface::class);
        $tracer->expects($this->once())
            ->method('startSpan')
            ->with('http.GET')
            ->willReturn(new NoOpSpan());

        $middleware = new TracingMiddleware($tracer);
        $event = new ControllerEvent(
            $this->createMock(HttpKernelInterface::class),
            [new PlainController(), 'index'],
            Request::create('/orders', 'GET'),
            HttpKernelInterface::MAIN_REQUEST,
        );

        $middleware->onController($event);
    }
}

final class PlainController
{
    public function index(): void
    {
    }
}
