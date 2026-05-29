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

    private PaddleClientConfig        $clientConfig;
    private PaddleCircuitBreakerConfig $circuitBreakerConfig;
    private PaddleSecurityConfig      $securityConfig;
    private PaddleWebhooksConfig      $webhooksConfig;
    private PaddleObservabilityConfig $observabilityConfig;

    public function __construct()
    {
        $this->mode               = $_ENV['PADDLE_MODE'] ?? 'sandbox';
        $this->apiKey             = $_ENV['PADDLE_API_KEY'] ?? '';
        $this->notificationSecret = $_ENV['PADDLE_NOTIFICATION_SECRET'] ?? '';
        $this->webhookPath        = $_ENV['PADDLE_WEBHOOK_PATH'] ?? '/webhooks/paddle';

        $this->clientConfig          = new PaddleClientConfig();
        $this->circuitBreakerConfig  = new PaddleCircuitBreakerConfig();
        $this->securityConfig        = new PaddleSecurityConfig();
        $this->webhooksConfig        = new PaddleWebhooksConfig();
        $this->observabilityConfig   = new PaddleObservabilityConfig();
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

    public function toArray(): array
    {
        return [
            'mode'                => $this->mode,
            'api_key'             => $this->apiKey,
            'notification_secret' => $this->notificationSecret,
            'webhook_path'        => $this->webhookPath,
            'client'              => $this->clientConfig->toArray(),
            'circuit_breaker'     => $this->circuitBreakerConfig->toArray(),
            'security'            => $this->securityConfig->toArray(),
            'webhooks'            => $this->webhooksConfig->toArray(),
            'observability'       => $this->observabilityConfig->toArray(),
        ];
    }
}
