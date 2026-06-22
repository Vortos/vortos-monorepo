<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\Guardrail;

use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Vortos\FeatureFlags\Guardrail\GuardrailMetricKind;
use Vortos\FeatureFlags\Guardrail\GuardrailMetricQuery;
use Vortos\FeatureFlags\Guardrail\MetricSource\PrometheusGuardrailMetricSource;

final class PrometheusMetricSourceTest extends TestCase
{
    public function test_successful_response_parsed_to_float(): void
    {
        $source = $this->source($this->response(200, json_encode([
            'status' => 'success',
            'data'   => ['result' => [['value' => [1718000000, '0.42']]]],
        ])));

        $this->assertSame(0.42, $source->query($this->query()));
    }

    public function test_http_5xx_returns_null(): void
    {
        $source = $this->source($this->response(503, 'service unavailable'));

        $this->assertNull($source->query($this->query()));
    }

    public function test_client_exception_returns_null(): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->method('sendRequest')->willThrowException(new class extends \Exception implements ClientExceptionInterface {});

        $source = new PrometheusGuardrailMetricSource($client, $this->requestFactory(), 'http://prom:9090');

        $this->assertNull($source->query($this->query()));
    }

    public function test_invalid_json_returns_null(): void
    {
        $source = $this->source($this->response(200, 'not json at all'));

        $this->assertNull($source->query($this->query()));
    }

    public function test_empty_result_set_returns_null(): void
    {
        $source = $this->source($this->response(200, json_encode([
            'status' => 'success',
            'data'   => ['result' => []],
        ])));

        $this->assertNull($source->query($this->query()));
    }

    public function test_non_success_status_returns_null(): void
    {
        $source = $this->source($this->response(200, json_encode([
            'status' => 'error',
            'error'  => 'bad query',
        ])));

        $this->assertNull($source->query($this->query()));
    }

    private function source(ResponseInterface $response): PrometheusGuardrailMetricSource
    {
        $client = $this->createMock(ClientInterface::class);
        $client->method('sendRequest')->willReturn($response);

        return new PrometheusGuardrailMetricSource($client, $this->requestFactory(), 'http://prom:9090');
    }

    private function requestFactory(): RequestFactoryInterface
    {
        $factory = $this->createMock(RequestFactoryInterface::class);
        $factory->method('createRequest')->willReturn($this->createMock(RequestInterface::class));

        return $factory;
    }

    private function response(int $status, string $body): ResponseInterface
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn($body);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('getBody')->willReturn($stream);

        return $response;
    }

    private function query(): GuardrailMetricQuery
    {
        return new GuardrailMetricQuery(GuardrailMetricKind::ErrorRate, 'checkout', 'production', 300);
    }
}
