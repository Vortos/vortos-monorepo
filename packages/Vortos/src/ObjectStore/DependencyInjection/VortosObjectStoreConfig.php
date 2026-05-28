<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\DependencyInjection;

final class VortosObjectStoreConfig
{
    private string $driver;
    private string $provider;
    private string $region;
    private ObjectStoreClientConfig $clientConfig;
    private ObjectStoreBucketConfig $bucketConfig;
    private ObjectStoreRetryConfig $retryConfig;
    private ObjectStoreAuditConfig $auditConfig;
    private ObjectStoreMultipartConfig $multipartConfig;
    private ObjectStoreOutboxConfig $outboxConfig;
    private ObjectStoreLifecycleConfig $lifecycleConfig;
    private ObjectStoreCircuitBreakerConfig $circuitBreakerConfig;
    private ObjectStoreObservabilityConfig $observabilityConfig;

    public function __construct()
    {
        $this->driver = $_ENV['VORTOS_OBJECT_STORE_DRIVER'] ?? 'log';
        $this->provider = $_ENV['OBJECT_STORE_PROVIDER'] ?? 'r2';
        $this->region = $_ENV['OBJECT_STORE_REGION'] ?? 'auto';
        $this->clientConfig = new ObjectStoreClientConfig();
        $this->bucketConfig = new ObjectStoreBucketConfig();
        $this->retryConfig = new ObjectStoreRetryConfig();
        $this->auditConfig = new ObjectStoreAuditConfig();
        $this->multipartConfig = new ObjectStoreMultipartConfig();
        $this->outboxConfig = new ObjectStoreOutboxConfig();
        $this->lifecycleConfig = new ObjectStoreLifecycleConfig();
        $this->circuitBreakerConfig = new ObjectStoreCircuitBreakerConfig();
        $this->observabilityConfig = new ObjectStoreObservabilityConfig();

        $this->clientConfig
            ->endpoint($_ENV['OBJECT_STORE_ENDPOINT'] ?? null)
            ->accountId($_ENV['OBJECT_STORE_ACCOUNT_ID'] ?? null)
            ->credentials(
                $_ENV['OBJECT_STORE_ACCESS_KEY_ID'] ?? null,
                $_ENV['OBJECT_STORE_SECRET_ACCESS_KEY'] ?? null,
            );

        $this->bucketConfig
            ->name($_ENV['OBJECT_STORE_BUCKET'] ?? '')
            ->keyPrefix($_ENV['OBJECT_STORE_KEY_PREFIX'] ?? '')
            ->temporaryKeyPrefix($_ENV['OBJECT_STORE_TEMPORARY_KEY_PREFIX'] ?? 'tmp')
            ->publicBaseUrl($_ENV['OBJECT_STORE_PUBLIC_BASE_URL'] ?? null);
    }

    public function driver(string $driver): static
    {
        $this->driver = $driver;
        return $this;
    }

    public function provider(string $provider): static
    {
        $this->provider = $provider;
        return $this;
    }

    public function region(string $region): static
    {
        $this->region = $region;
        return $this;
    }

    public function endpoint(?string $endpoint): static
    {
        $this->clientConfig->endpoint($endpoint);
        return $this;
    }

    public function bucket(string $bucket): static
    {
        $this->bucketConfig->name($bucket);
        return $this;
    }

    public function client(): ObjectStoreClientConfig
    {
        return $this->clientConfig;
    }

    public function bucketConfig(): ObjectStoreBucketConfig
    {
        return $this->bucketConfig;
    }

    public function retry(): ObjectStoreRetryConfig
    {
        return $this->retryConfig;
    }

    public function audit(): ObjectStoreAuditConfig
    {
        return $this->auditConfig;
    }

    public function multipart(): ObjectStoreMultipartConfig
    {
        return $this->multipartConfig;
    }

    public function outbox(): ObjectStoreOutboxConfig
    {
        return $this->outboxConfig;
    }

    public function lifecycle(): ObjectStoreLifecycleConfig
    {
        return $this->lifecycleConfig;
    }

    public function circuitBreaker(): ObjectStoreCircuitBreakerConfig
    {
        return $this->circuitBreakerConfig;
    }

    public function observability(): ObjectStoreObservabilityConfig
    {
        return $this->observabilityConfig;
    }

    /** @internal */
    public function toArray(): array
    {
        return [
            'driver'    => $this->driver,
            'provider'  => $this->provider,
            'region'    => $this->region,
            'client'    => $this->clientConfig->toArray(),
            'bucket'    => $this->bucketConfig->toArray(),
            'retry'     => $this->retryConfig->toArray(),
            'audit'     => $this->auditConfig->toArray(),
            'multipart' => $this->multipartConfig->toArray(),
            'outbox' => $this->outboxConfig->toArray(),
            'lifecycle'       => $this->lifecycleConfig->toArray(),
            'circuit_breaker' => $this->circuitBreakerConfig->toArray(),
            'observability'   => $this->observabilityConfig->toArray(),
        ];
    }
}
