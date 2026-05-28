<?php

declare(strict_types=1);

namespace Vortos\AwsSes\DependencyInjection;

/**
 * Fluent configuration object for vortos-aws-ses.
 *
 * Loaded via require in AwsSesExtension::load().
 * Every setting has a sensible default — no config file required for basic usage.
 *
 * ## Minimal usage (just set env vars, no config file needed)
 *
 *   VORTOS_MAILER_DRIVER=ses
 *   AWS_SES_REGION=us-east-1
 *   AWS_ACCESS_KEY_ID=...
 *   AWS_SECRET_ACCESS_KEY=...
 *   SES_FROM_ADDRESS=no-reply@example.com
 *
 * ## Full config file: config/aws_ses.php
 *
 *   return static function (VortosAwsSesConfig $config): void {
 *       $config
 *           ->driver($_ENV['VORTOS_MAILER_DRIVER'] ?? 'log')
 *           ->region($_ENV['AWS_SES_REGION'] ?? 'us-east-1')
 *           ->defaultFrom($_ENV['SES_FROM_ADDRESS'] ?? '', $_ENV['SES_FROM_NAME'] ?? '');
 *
 *       $config->outbox()->batchSize(100)->maxDeliveryAttempts(3);
 *       $config->awsClient()->endpointOverride($_ENV['AWS_ENDPOINT'] ?? null);
 *   };
 *
 * ## Per-environment overrides: config/{env}/aws_ses.php
 *
 *   // config/test/aws_ses.php
 *   return static function (VortosAwsSesConfig $config): void {
 *       $config->driver('null');
 *   };
 */
final class VortosAwsSesConfig
{
    private string $driver;
    private string $region;
    private ?string $fallbackRegion;
    private string $defaultFromAddress;
    private string $defaultFromName;
    private ?string $replyTo;
    private ?string $configurationSet;
    private ?string $templateDir = null;

    private AwsSesClientConfig $awsClientConfig;
    private AwsSesOutboxConfig $outboxConfig;
    private AwsSesWebhookConfig $webhookConfig;
    private AwsSesSuppressionConfig $suppressionConfig;
    private AwsSesRateLimitConfig $rateLimitConfig;
    private AwsSesAuditLogConfig $auditLogConfig;
    private AwsSesCircuitBreakerConfig $circuitBreakerConfig;
    private AwsSesObservabilityConfig $observabilityConfig;

    public function __construct()
    {
        $this->driver             = $_ENV['VORTOS_MAILER_DRIVER'] ?? 'log';
        $this->region             = $_ENV['AWS_SES_REGION'] ?? 'us-east-1';
        $this->fallbackRegion     = $_ENV['AWS_SES_FALLBACK_REGION'] ?? null;
        $this->defaultFromAddress = $_ENV['SES_FROM_ADDRESS'] ?? '';
        $this->defaultFromName    = $_ENV['SES_FROM_NAME'] ?? '';
        $this->replyTo            = $_ENV['SES_REPLY_TO'] ?? null;
        $this->configurationSet   = $_ENV['SES_CONFIGURATION_SET'] ?? null;

        $this->awsClientConfig      = new AwsSesClientConfig();
        $this->outboxConfig         = new AwsSesOutboxConfig();
        $this->webhookConfig        = new AwsSesWebhookConfig();
        $this->suppressionConfig    = new AwsSesSuppressionConfig();
        $this->rateLimitConfig      = new AwsSesRateLimitConfig();
        $this->auditLogConfig       = new AwsSesAuditLogConfig();
        $this->circuitBreakerConfig = new AwsSesCircuitBreakerConfig();
        $this->observabilityConfig  = new AwsSesObservabilityConfig();

        // Apply endpoint override from env for custom SES-compatible endpoints.
        if (isset($_ENV['AWS_ENDPOINT'])) {
            $this->awsClientConfig->endpointOverride($_ENV['AWS_ENDPOINT']);
        }
    }

    /**
     * Set the mailer driver.
     * ses — real AWS SES (requires credentials)
     * log — writes to PSR logger (dev/staging)
     * null — silent drop, SesMailerFake in container (testing)
     */
    public function driver(string $driver): static
    {
        $this->driver = $driver;
        return $this;
    }

    /**
     * Primary AWS SES region.
     */
    public function region(string $region): static
    {
        $this->region = $region;
        return $this;
    }

    /**
     * Fallback AWS SES region. When set, MultiRegionMailer wraps the primary mailer.
     * Leave null to disable multi-region failover.
     */
    public function fallbackRegion(?string $region): static
    {
        $this->fallbackRegion = $region;
        return $this;
    }

    /**
     * Default sender address and display name applied when Email::$from is not set.
     */
    public function defaultFrom(string $address, string $name = ''): static
    {
        $this->defaultFromAddress = $address;
        $this->defaultFromName    = $name;
        return $this;
    }

    /**
     * Default Reply-To address applied to all outgoing email.
     */
    public function replyTo(?string $address): static
    {
        $this->replyTo = $address;
        return $this;
    }

    /**
     * AWS SES configuration set name for open/click/bounce tracking at the AWS level.
     */
    public function configurationSet(?string $name): static
    {
        $this->configurationSet = $name;
        return $this;
    }

    /**
     * Directory containing PHP email templates (*.html.php, *.text.php).
     * When null, NullTemplateRenderer is used and render() throws on any call.
     */
    public function templateDir(?string $dir): static
    {
        $this->templateDir = $dir;
        return $this;
    }

    public function awsClient(): AwsSesClientConfig
    {
        return $this->awsClientConfig;
    }

    public function outbox(): AwsSesOutboxConfig
    {
        return $this->outboxConfig;
    }

    public function webhooks(): AwsSesWebhookConfig
    {
        return $this->webhookConfig;
    }

    public function suppression(): AwsSesSuppressionConfig
    {
        return $this->suppressionConfig;
    }

    public function rateLimit(): AwsSesRateLimitConfig
    {
        return $this->rateLimitConfig;
    }

    public function auditLog(): AwsSesAuditLogConfig
    {
        return $this->auditLogConfig;
    }

    public function circuitBreaker(): AwsSesCircuitBreakerConfig
    {
        return $this->circuitBreakerConfig;
    }

    public function observability(): AwsSesObservabilityConfig
    {
        return $this->observabilityConfig;
    }

    /** @internal Used by AwsSesExtension */
    public function toArray(): array
    {
        return [
            'driver'               => $this->driver,
            'region'               => $this->region,
            'fallback_region'      => $this->fallbackRegion,
            'default_from_address' => $this->defaultFromAddress,
            'default_from_name'    => $this->defaultFromName,
            'reply_to'             => $this->replyTo,
            'configuration_set'    => $this->configurationSet,
            'template_dir'         => $this->templateDir,
            'aws_client'           => $this->awsClientConfig->toArray(),
            'outbox'               => $this->outboxConfig->toArray(),
            'webhooks'             => $this->webhookConfig->toArray(),
            'suppression'          => $this->suppressionConfig->toArray(),
            'rate_limit'           => $this->rateLimitConfig->toArray(),
            'audit_log'            => $this->auditLogConfig->toArray(),
            'circuit_breaker'      => $this->circuitBreakerConfig->toArray(),
            'observability'        => $this->observabilityConfig->toArray(),
        ];
    }
}
