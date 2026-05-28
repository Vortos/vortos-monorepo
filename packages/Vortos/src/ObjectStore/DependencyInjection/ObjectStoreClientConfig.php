<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\DependencyInjection;

final class ObjectStoreClientConfig
{
    private ?string $endpoint = null;
    private ?string $accountId = null;
    private ?string $accessKeyId = null;
    private ?string $secretAccessKey = null;
    private float $httpTimeout = 10.0;
    private float $connectTimeout = 2.0;
    private int $maxRetries = 3;
    private bool $pathStyleEndpoint = false;

    public function endpoint(?string $endpoint): static
    {
        $this->endpoint = $endpoint;
        return $this;
    }

    public function accountId(?string $accountId): static
    {
        $this->accountId = $accountId;
        return $this;
    }

    public function credentials(?string $accessKeyId, ?string $secretAccessKey): static
    {
        $this->accessKeyId = $accessKeyId;
        $this->secretAccessKey = $secretAccessKey;
        return $this;
    }

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

    public function pathStyleEndpoint(bool $enabled): static
    {
        $this->pathStyleEndpoint = $enabled;
        return $this;
    }

    /** @internal */
    public function toArray(): array
    {
        return [
            'endpoint'            => $this->endpoint,
            'account_id'           => $this->accountId,
            'access_key_id'       => $this->accessKeyId,
            'secret_access_key'   => $this->secretAccessKey,
            'http_timeout'        => $this->httpTimeout,
            'connect_timeout'     => $this->connectTimeout,
            'max_retries'         => $this->maxRetries,
            'path_style_endpoint' => $this->pathStyleEndpoint,
        ];
    }
}
