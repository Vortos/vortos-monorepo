<?php
declare(strict_types=1);

namespace Vortos\Auth\RateLimit\Storage;

use Vortos\Auth\RateLimit\CircuitBreaker\RateLimitCircuitBreaker;
use Vortos\Auth\RateLimit\Contract\RateLimitStoreInterface;
use Vortos\Auth\RateLimit\Exception\RateLimitStoreUnavailableException;

final class ResilientRateLimitStore implements RateLimitStoreInterface
{
    public function __construct(
        private RateLimitStoreInterface $inner,
        private RateLimitCircuitBreaker $circuitBreaker,
    ) {}

    public function increment(string $key, int $windowSeconds): int
    {
        if (!$this->circuitBreaker->isAvailable()) {
            throw new RateLimitStoreUnavailableException('Rate limit store circuit breaker is open.');
        }

        try {
            $result = $this->inner->increment($key, $windowSeconds);
            $this->circuitBreaker->recordSuccess();
            return $result;
        } catch (RateLimitStoreUnavailableException $e) {
            $this->circuitBreaker->recordFailure();
            throw $e;
        }
    }

    public function getTtl(string $key): int
    {
        if (!$this->circuitBreaker->isAvailable()) {
            throw new RateLimitStoreUnavailableException('Rate limit store circuit breaker is open.');
        }

        try {
            $result = $this->inner->getTtl($key);
            $this->circuitBreaker->recordSuccess();
            return $result;
        } catch (RateLimitStoreUnavailableException $e) {
            $this->circuitBreaker->recordFailure();
            throw $e;
        }
    }

    public function reset(string $key): void
    {
        if (!$this->circuitBreaker->isAvailable()) {
            throw new RateLimitStoreUnavailableException('Rate limit store circuit breaker is open.');
        }

        try {
            $this->inner->reset($key);
            $this->circuitBreaker->recordSuccess();
        } catch (RateLimitStoreUnavailableException $e) {
            $this->circuitBreaker->recordFailure();
            throw $e;
        }
    }

    public function circuitBreaker(): RateLimitCircuitBreaker
    {
        return $this->circuitBreaker;
    }
}
