<?php

declare(strict_types=1);

namespace Vortos\Paddle\DependencyInjection;

final class PaddleClientConfig
{
    private float $httpTimeout               = 10.0;
    private float $connectTimeout            = 3.0;
    private int   $maxRetries                = 3;
    private bool  $retryOnRateLimit          = true;
    private int   $idempotencyKeyTtlSeconds  = 86400;

    public function httpTimeout(float $seconds): static
    {
        $this->httpTimeout = $seconds;
        return $this;
    }

    public function connectTimeout(float $seconds): static
    {
        $this->connectTimeout = $seconds;
        return $this;
    }

    public function maxRetries(int $retries): static
    {
        $this->maxRetries = $retries;
        return $this;
    }

    public function retryOnRateLimit(bool $retry): static
    {
        $this->retryOnRateLimit = $retry;
        return $this;
    }

    public function idempotencyKeyTtlSeconds(int $seconds): static
    {
        $this->idempotencyKeyTtlSeconds = $seconds;
        return $this;
    }

    public function toArray(): array
    {
        return [
            'http_timeout'               => $this->httpTimeout,
            'connect_timeout'            => $this->connectTimeout,
            'max_retries'                => $this->maxRetries,
            'retry_on_rate_limit'        => $this->retryOnRateLimit,
            'idempotency_key_ttl_seconds' => $this->idempotencyKeyTtlSeconds,
        ];
    }
}
