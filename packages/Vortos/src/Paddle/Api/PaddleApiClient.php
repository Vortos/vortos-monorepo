<?php

declare(strict_types=1);

namespace Vortos\Paddle\Api;

use Paddle\SDK\Client;
use Paddle\SDK\Exceptions\ApiError;
use Vortos\Paddle\Exception\PaddleApiException;
use Vortos\Paddle\Exception\PaddleCircuitOpenException;
use Vortos\Paddle\Exception\PaddleRateLimitException;
use Vortos\Paddle\Failover\PaddleCircuitBreaker;

final class PaddleApiClient implements PaddleApiClientInterface
{
    public function __construct(
        private readonly Client                   $sdk,
        private readonly PaddleCircuitBreaker      $circuitBreaker,
        private readonly PaddleSdkExceptionMapper  $exceptionMapper,
        private readonly int                       $maxRetries,
        private readonly bool                      $retryOnRateLimit,
    ) {}

    public function sdk(): Client
    {
        return $this->sdk;
    }

    /**
     * Executes a callable against the Paddle SDK with circuit breaker and rate limit protection.
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     * @throws PaddleApiException
     * @throws PaddleCircuitOpenException
     */
    public function call(callable $operation): mixed
    {
        if (!$this->circuitBreaker->isAvailable()) {
            throw PaddleCircuitOpenException::create();
        }

        $attempts = 0;

        retry:
        $attempts++;

        try {
            $result = $operation();
            $this->circuitBreaker->recordSuccess();
            return $result;
        } catch (ApiError $e) {
            // ApiError = API responded → not an infrastructure failure
            $this->circuitBreaker->recordSuccess();

            $mapped = $this->exceptionMapper->map($e);

            if ($mapped instanceof PaddleRateLimitException
                && $this->retryOnRateLimit
                && $attempts <= $this->maxRetries
            ) {
                $sleep = max(1, $mapped->retryAfterSeconds);
                sleep($sleep);
                goto retry;
            }

            throw $mapped;
        } catch (\Throwable $e) {
            // Non-ApiError = network/infrastructure failure
            $this->circuitBreaker->recordFailure();
            throw new PaddleApiException(
                sprintf('Paddle API request failed: %s', $e->getMessage()),
                previous: $e,
            );
        }
    }
}
