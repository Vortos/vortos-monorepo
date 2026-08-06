<?php

declare(strict_types=1);

namespace Vortos\Paddle\DependencyInjection;

final class VortosPaddleConfig
{
    private string       $mode;
    private string       $apiKey;
    private string       $notificationSecret;
    private array        $notificationSecrets = [];
    private string       $webhookPath;
    private string       $defaultProductId;

    private PaddleClientConfig        $clientConfig;
    private PaddleCircuitBreakerConfig $circuitBreakerConfig;
    private PaddleSecurityConfig      $securityConfig;
    private PaddleWebhooksConfig      $webhooksConfig;
    private PaddleObservabilityConfig $observabilityConfig;
    private PaddleOutboxConfig        $outboxConfig;

    public function __construct()
    {
        $this->mode               = $_ENV['PADDLE_MODE'] ?? 'sandbox';
        $this->apiKey             = $_ENV['PADDLE_API_KEY'] ?? '';
        $this->notificationSecret = $_ENV['PADDLE_NOTIFICATION_SECRET'] ?? '';
        $this->webhookPath        = $_ENV['PADDLE_WEBHOOK_PATH'] ?? '/webhooks/paddle';

        // The product every ad-hoc (non-catalog) charge line hangs off. Paddle
        // has no product-less line, and one shared product keeps the price
        // catalog from growing a row per registration.
        $this->defaultProductId   = $_ENV['PADDLE_DEFAULT_PRODUCT_ID'] ?? ($_ENV['PADDLE_REGISTRATION_PRODUCT_ID'] ?? '');

        $this->clientConfig          = new PaddleClientConfig();
        $this->circuitBreakerConfig  = new PaddleCircuitBreakerConfig();
        $this->securityConfig        = new PaddleSecurityConfig();
        $this->webhooksConfig        = new PaddleWebhooksConfig();
        $this->observabilityConfig   = new PaddleObservabilityConfig();
        $this->outboxConfig          = new PaddleOutboxConfig();
    }

    public function mode(string $mode): static
    {
        $this->mode = $mode;
        return $this;
    }

    public function apiKey(string $apiKey): static
    {
        $this->apiKey = $apiKey;
        return $this;
    }

    public function notificationSecret(string $secret): static
    {
        $this->notificationSecret = $secret;
        return $this;
    }

    public function webhookPath(string $path): static
    {
        $this->webhookPath = $path;
        return $this;
    }

    public function defaultProductId(string $productId): static
    {
        $this->defaultProductId = $productId;
        return $this;
    }

    public function client(): PaddleClientConfig
    {
        return $this->clientConfig;
    }

    public function circuitBreaker(): PaddleCircuitBreakerConfig
    {
        return $this->circuitBreakerConfig;
    }

    public function security(): PaddleSecurityConfig
    {
        return $this->securityConfig;
    }

    public function webhooks(): PaddleWebhooksConfig
    {
        return $this->webhooksConfig;
    }

    public function observability(): PaddleObservabilityConfig
    {
        return $this->observabilityConfig;
    }

    public function outbox(): PaddleOutboxConfig
    {
        return $this->outboxConfig;
    }

    public function toArray(): array
    {
        return [
            'mode'                => $this->mode,
            'api_key'             => $this->apiKey,
            'notification_secret' => $this->notificationSecret,
            'webhook_path'        => $this->webhookPath,
            'default_product_id'  => $this->defaultProductId,
            'client'              => $this->clientConfig->toArray(),
            'circuit_breaker'     => $this->circuitBreakerConfig->toArray(),
            'security'            => $this->securityConfig->toArray(),
            'webhooks'            => $this->webhooksConfig->toArray(),
            'observability'       => $this->observabilityConfig->toArray(),
            'outbox'              => $this->outboxConfig->toArray(),
        ];
    }
}
