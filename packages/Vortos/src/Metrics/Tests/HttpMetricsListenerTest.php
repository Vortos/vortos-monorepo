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

        $names = [];
        $this->metrics->expects($this->exactly(2))
            ->method('counter')
            ->willReturnCallback(function (string $name, array $labels) use ($counter, &$names): CounterInterface {
                $names[] = $name;
                if ($name === 'http_requests_total') {
                    $this->assertArrayHasKey('method', $labels);
                }
                return $counter;
            });

        $this->metrics->expects($this->once())
            ->method('histogram')
            ->with('http_request_duration_ms', $this->arrayHasKey('method'))
            ->willReturn($histogram);

        $counter->expects($this->exactly(2))->method('increment');
        $histogram->expects($this->once())->method('observe')->with($this->isFloat());

        $this->listener->handle($request, fn($r) => new Response('', 200));
        $this->assertSame(['http_requests_total', 'http_client_address_total'], $names);
    }

    public function test_blocked_request_records_blocked_counter(): void
    {
        $request = Request::create('/api/test', 'GET');
        $request->attributes->set(TelemetryRequestAttributes::BLOCKED_REASON, 'rate_limit');

        $counter = $this->createMock(CounterInterface::class);

        $this->metrics->expects($this->exactly(3))
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
        $counter->expects($this->exactly(3))->method('increment');

        $this->listener->handle($request, fn($r) => new Response('', 429));
    }

    /** @return iterable<string, array{list<string>, array<string, string>, string}> */
    public static function clientAddressCases(): iterable
    {
        yield 'no forwarding header' => [['172.22.0.0/16'], [], 'direct'];
        yield 'forwarded by the trusted edge' => [['172.22.0.0/16'], ['REMOTE_ADDR' => '172.22.0.4', 'HTTP_X_FORWARDED_FOR' => '203.0.113.7'], 'forwarded'];
        // The edge moved networks and trusted_proxies did not follow: the case that went unnoticed.
        yield 'forwarded by a peer that is not trusted' => [['172.18.0.0/16'], ['REMOTE_ADDR' => '172.22.0.4', 'HTTP_X_FORWARDED_FOR' => '203.0.113.7'], 'untrusted_forwarder'];
        yield 'nothing trusted at all' => [[], ['REMOTE_ADDR' => '172.22.0.4', 'HTTP_X_FORWARDED_FOR' => '203.0.113.7'], 'untrusted_forwarder'];
    }

    /**
     * @param list<string>          $trusted
     * @param array<string, string> $server
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('clientAddressCases')]
    public function test_client_address_resolution_is_counted(array $trusted, array $server, string $expected): void
    {
        $previous = [Request::getTrustedProxies(), Request::getTrustedHeaderSet()];
        Request::setTrustedProxies($trusted, Request::HEADER_X_FORWARDED_FOR);

        try {
            $seen = null;
            $this->metrics->method('counter')->willReturnCallback(
                function (string $name, array $labels) use (&$seen): CounterInterface {
                    if ($name === 'http_client_address_total') {
                        $seen = $labels;
                    }
                    return $this->createMock(CounterInterface::class);
                },
            );
            $this->metrics->method('histogram')->willReturn($this->createMock(HistogramInterface::class));

            $this->listener->handle(Request::create('/api/me', 'GET', server: $server), fn($r) => new Response('', 200));

            $this->assertSame(['result' => $expected], $seen);
        } finally {
            Request::setTrustedProxies($previous[0], $previous[1]);
        }
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
