<?php

declare(strict_types=1);

namespace Vortos\Ses\DependencyInjection;

/**
 * Fluent configuration object for vortos-ses.
 *
 * Loaded via require in SesExtension::load().
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
 * ## Full config file: config/ses.php
 *
 *   return static function (VortosSesConfig $config): void {
 *       $config
 *           ->driver($_ENV['VORTOS_MAILER_DRIVER'] ?? 'log')
 *           ->region($_ENV['AWS_SES_REGION'] ?? 'us-east-1')
 *           ->defaultFrom($_ENV['SES_FROM_ADDRESS'] ?? '', $_ENV['SES_FROM_NAME'] ?? '');
 *
 *       $config->outbox()->batchSize(100)->maxDeliveryAttempts(3);
 *       $config->awsClient()->endpointOverride($_ENV['AWS_ENDPOINT'] ?? null);
 *   };
 *
 * ## Per-environment overrides: config/{env}/ses.php
 *
 *   // config/test/ses.php
 *   return static function (VortosSesConfig $config): void {
 *       $config->driver('null');
 *   };
 */
final class VortosSesConfig
{
    private string $driver;
    private string $region;
    private ?string $fallbackRegion;
    private string $defaultFromAddress;
    private string $defaultFromName;
    private ?string $replyTo;
    private ?string $configurationSet;
    private ?string $templateDir = null;

    private SesAwsClientConfig $awsClientConfig;
    private SesOutboxConfig $outboxConfig;
    private SesWebhookConfig $webhookConfig;
    private SesSuppressionConfig $suppressionConfig;
    private SesRateLimitConfig $rateLimitConfig;
    private SesAuditLogConfig $auditLogConfig;
    private SesCircuitBreakerConfig $circuitBreakerConfig;

    public function __construct()
    {
        $this->driver             = $_ENV['VORTOS_MAILER_DRIVER'] ?? 'log';
        $this->region             = $_ENV['AWS_SES_REGION'] ?? 'us-east-1';
        $this->fallbackRegion     = $_ENV['AWS_SES_FALLBACK_REGION'] ?? null;
        $this->defaultFromAddress = $_ENV['SES_FROM_ADDRESS'] ?? '';
        $this->defaultFromName    = $_ENV['SES_FROM_NAME'] ?? '';
        $this->replyTo            = $_ENV['SES_REPLY_TO'] ?? null;
        $this->configurationSet   = $_ENV['SES_CONFIGURATION_SET'] ?? null;

        $this->awsClientConfig      = new SesAwsClientConfig();
        $this->outboxConfig         = new SesOutboxConfig();
        $this->webhookConfig        = new SesWebhookConfig();
        $this->suppressionConfig    = new SesSuppressionConfig();
        $this->rateLimitConfig      = new SesRateLimitConfig();
        $this->auditLogConfig       = new SesAuditLogConfig();
        $this->circuitBreakerConfig = new SesCircuitBreakerConfig();

        // Apply endpoint override from env so LocalStack works with zero config file
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

    public function awsClient(): SesAwsClientConfig
    {
        return $this->awsClientConfig;
    }

    public function outbox(): SesOutboxConfig
    {
        return $this->outboxConfig;
    }

    public function webhooks(): SesWebhookConfig
    {
        return $this->webhookConfig;
    }

    public function suppression(): SesSuppressionConfig
    {
        return $this->suppressionConfig;
    }

    public function rateLimit(): SesRateLimitConfig
    {
        return $this->rateLimitConfig;
    }

    public function auditLog(): SesAuditLogConfig
    {
        return $this->auditLogConfig;
    }

    public function circuitBreaker(): SesCircuitBreakerConfig
    {
        return $this->circuitBreakerConfig;
    }

    /** @internal Used by SesExtension */
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
        ];
    }
}
