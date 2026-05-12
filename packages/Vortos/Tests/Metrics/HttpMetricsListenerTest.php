<?php

declare(strict_types=1);

namespace Vortos\Tests\Metrics;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Vortos\Metrics\AutoInstrumentation\HttpMetricsListener;
use Vortos\Metrics\Contract\CounterInterface;
use Vortos\Metrics\Contract\HistogramInterface;
use Vortos\Metrics\Contract\MetricsInterface;
use Vortos\Metrics\Telemetry\FrameworkTelemetry;
use Vortos\Observability\Telemetry\TelemetryRequestAttributes;

final class HttpMetricsListenerTest extends TestCase
{
    private MetricsInterface&MockObject $metrics;
    private HttpMetricsListener $listener;

    protected function setUp(): void
    {
        $this->metrics  = $this->createMock(MetricsInterface::class);
        $this->listener = new HttpMetricsListener(new FrameworkTelemetry($this->metrics));
    }

    public function test_subscribes_to_request_and_response_events(): void
    {
        $events = HttpMetricsListener::getSubscribedEvents();
        $this->assertArrayHasKey(KernelEvents::REQUEST, $events);
        $this->assertArrayHasKey(KernelEvents::RESPONSE, $events);
    }

    public function test_response_event_records_counter_and_histogram(): void
    {
        $kernel  = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/api/test', 'GET');
        $request->attributes->set('_route', 'api_test');
        $response = new Response('', 200);

        $counter   = $this->createMock(CounterInterface::class);
        $histogram = $this->createMock(HistogramInterface::class);

        $this->metrics->expects($this->once())
            ->method('counter')
            ->with('http_requests_total', $this->arrayHasKey('method'))
            ->willReturn($counter);

        $this->metrics->expects($this->once())
            ->method('histogram')
            ->with('http_request_duration_ms', $this->arrayHasKey('method'))
            ->willReturn($histogram);

        $counter->expects($this->once())->method('increment');
        $histogram->expects($this->once())->method('observe')->with($this->isFloat());

        $requestEvent = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
        $this->listener->onRequest($requestEvent);

        $responseEvent = new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);
        $this->listener->onResponse($responseEvent);
    }

    public function test_sub_request_is_skipped(): void
    {
        $kernel   = $this->createMock(HttpKernelInterface::class);
        $request  = Request::create('/sub', 'GET');
        $response = new Response();

        $this->metrics->expects($this->never())->method('counter');
        $this->metrics->expects($this->never())->method('histogram');

        $requestEvent = new RequestEvent($kernel, $request, HttpKernelInterface::SUB_REQUEST);
        $this->listener->onRequest($requestEvent);

        $responseEvent = new ResponseEvent($kernel, $request, HttpKernelInterface::SUB_REQUEST, $response);
        $this->listener->onResponse($responseEvent);
    }

    public function test_blocked_request_records_cheap_blocked_counter(): void
    {
        $kernel  = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/api/test', 'GET');
        $request->attributes->set(TelemetryRequestAttributes::BLOCKED_REASON, 'rate_limit');
        $response = new Response('', 429);
        $counter = $this->createMock(CounterInterface::class);

        $this->metrics->expects($this->exactly(2))
            ->method('counter')
            ->willReturnCallback(function (string $name, array $labels) use ($counter): CounterInterface {
                if ($name === 'http_blocked_total') {
                    $this->assertSame(['reason' => 'rate_limit', 'status' => '429'], $labels);
                }

                return $counter;
            });
        $this->metrics->expects($this->never())->method('histogram');
        $counter->expects($this->exactly(2))->method('increment');

        $responseEvent = new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);
        $this->listener->onResponse($responseEvent);
    }
}
