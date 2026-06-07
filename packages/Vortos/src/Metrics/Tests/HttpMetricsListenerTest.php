<?php

declare(strict_types=1);

namespace Vortos\Metrics\Tests;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Vortos\Http\Request;
use Vortos\Http\Response;
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

    public function test_handle_records_counter_and_histogram(): void
    {
        $request = Request::create('/api/test', 'GET');
        $request->attributes->set('_route', 'api_test');

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

        $this->listener->handle($request, fn($r) => new Response('', 200));
    }

    public function test_blocked_request_records_blocked_counter(): void
    {
        $request = Request::create('/api/test', 'GET');
        $request->attributes->set(TelemetryRequestAttributes::BLOCKED_REASON, 'rate_limit');

        $counter = $this->createMock(CounterInterface::class);

        $this->metrics->expects($this->exactly(2))
            ->method('counter')
            ->willReturnCallback(function (string $name, array $labels) use ($counter): CounterInterface {
                if ($name === 'http_blocked_total') {
                    $this->assertSame(['reason' => 'rate_limit', 'status' => '429'], $labels);
                }
                return $counter;
            });
        $this->metrics->expects($this->once())->method('histogram')->willReturn(
            $this->createMock(HistogramInterface::class)
        );
        $counter->expects($this->exactly(2))->method('increment');

        $this->listener->handle($request, fn($r) => new Response('', 429));
    }

    public function test_response_is_passed_through_unchanged(): void
    {
        $counter   = $this->createMock(CounterInterface::class);
        $histogram = $this->createMock(HistogramInterface::class);
        $this->metrics->method('counter')->willReturn($counter);
        $this->metrics->method('histogram')->willReturn($histogram);

        $request = Request::create('/test', 'GET');
        $response = $this->listener->handle($request, fn($r) => new Response('body', 201));

        $this->assertSame(201, $response->getStatusCode());
    }
}
