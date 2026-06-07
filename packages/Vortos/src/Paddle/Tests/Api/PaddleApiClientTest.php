<?php

declare(strict_types=1);

namespace Vortos\Paddle\Tests\Api;

use Paddle\SDK\Client;
use Paddle\SDK\Exceptions\ApiError;
use PHPUnit\Framework\TestCase;
use Vortos\Paddle\Api\PaddleApiClient;
use Vortos\Paddle\Api\PaddleSdkExceptionMapper;
use Vortos\Paddle\Exception\PaddleApiException;
use Vortos\Paddle\Exception\PaddleCircuitOpenException;
use Vortos\Paddle\Exception\PaddleNotFoundException;
use Vortos\Paddle\Exception\PaddleRateLimitException;
use Vortos\Paddle\Failover\PaddleCircuitBreaker;
use Vortos\Paddle\Failover\PaddleCircuitBreakerState;

final class PaddleApiClientTest extends TestCase
{
    private function makeClient(
        int  $failureThreshold    = 5,
        int  $resetTimeoutSeconds = 60,
        int  $maxRetries          = 3,
        bool $retryOnRateLimit    = false,
    ): PaddleApiClient {
        $sdk = $this->createMock(Client::class);
        return new PaddleApiClient(
            sdk: $sdk,
            circuitBreaker: new PaddleCircuitBreaker($failureThreshold, $resetTimeoutSeconds),
            exceptionMapper: new PaddleSdkExceptionMapper(),
            maxRetries: $maxRetries,
            retryOnRateLimit: $retryOnRateLimit,
        );
    }

    private function makeApiError(string $type, string $code = 'some_error', ?int $retryAfter = null): ApiError
    {
        return ApiError::fromErrorData([
            'type'              => $type,
            'code'              => $code,
            'detail'            => 'Error detail',
            'documentation_url' => 'https://paddle.com/errors',
        ], $retryAfter);
    }

    public function test_successful_call_returns_result(): void
    {
        $client = $this->makeClient();
        $result = $client->call(fn() => 'success');
        $this->assertSame('success', $result);
    }

    public function test_api_error_is_mapped_to_vortos_exception(): void
    {
        $client = $this->makeClient();

        $this->expectException(PaddleNotFoundException::class);
        $client->call(fn() => throw $this->makeApiError('not_found', 'customer_not_found'));
    }

    public function test_api_error_does_not_trip_circuit_breaker(): void
    {
        $circuitBreaker = new PaddleCircuitBreaker(failureThreshold: 1, resetTimeoutSeconds: 60);
        $sdk            = $this->createMock(Client::class);
        $client         = new PaddleApiClient($sdk, $circuitBreaker, new PaddleSdkExceptionMapper(), 3, false);

        try {
            $client->call(fn() => throw $this->makeApiError('not_found'));
        } catch (PaddleNotFoundException) {}

        $this->assertSame(PaddleCircuitBreakerState::Closed, $circuitBreaker->state());
        $this->assertTrue($circuitBreaker->isAvailable());
    }

    public function test_network_error_trips_circuit_breaker(): void
    {
        $circuitBreaker = new PaddleCircuitBreaker(failureThreshold: 1, resetTimeoutSeconds: 60);
        $sdk            = $this->createMock(Client::class);
        $client         = new PaddleApiClient($sdk, $circuitBreaker, new PaddleSdkExceptionMapper(), 3, false);

        try {
            $client->call(fn() => throw new \RuntimeException('Connection refused'));
        } catch (PaddleApiException) {}

        $this->assertSame(PaddleCircuitBreakerState::Open, $circuitBreaker->state());
    }

    public function test_open_circuit_throws_immediately(): void
    {
        $circuitBreaker = new PaddleCircuitBreaker(failureThreshold: 1, resetTimeoutSeconds: 3600);
        $sdk            = $this->createMock(Client::class);
        $client         = new PaddleApiClient($sdk, $circuitBreaker, new PaddleSdkExceptionMapper(), 3, false);

        // Trip the circuit
        try { $client->call(fn() => throw new \RuntimeException('Network down')); } catch (\Throwable) {}

        $this->expectException(PaddleCircuitOpenException::class);
        $client->call(fn() => 'should not reach');
    }

    public function test_rate_limit_throws_when_retry_disabled(): void
    {
        $client = $this->makeClient(retryOnRateLimit: false);

        $this->expectException(PaddleRateLimitException::class);
        $client->call(fn() => throw $this->makeApiError('too_many_requests', 'rate_limit', retryAfter: 1));
    }

    public function test_sdk_is_accessible(): void
    {
        $client = $this->makeClient();
        $this->assertInstanceOf(Client::class, $client->sdk());
    }

    public function test_successful_call_records_circuit_breaker_success(): void
    {
        $circuitBreaker = new PaddleCircuitBreaker(failureThreshold: 3, resetTimeoutSeconds: 60);
        $sdk            = $this->createMock(Client::class);
        $client         = new PaddleApiClient($sdk, $circuitBreaker, new PaddleSdkExceptionMapper(), 3, false);

        // Record 2 failures
        try { $client->call(fn() => throw new \RuntimeException('error')); } catch (\Throwable) {}
        try { $client->call(fn() => throw new \RuntimeException('error')); } catch (\Throwable) {}
        $this->assertSame(2, $circuitBreaker->consecutiveFailures());

        // Successful call resets failures
        $client->call(fn() => 'ok');
        $this->assertSame(0, $circuitBreaker->consecutiveFailures());
    }
}
