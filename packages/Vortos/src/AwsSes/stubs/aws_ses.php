<?php

declare(strict_types=1);

use Vortos\AwsSes\DependencyInjection\VortosAwsSesConfig;
use Vortos\AwsSes\Config\AwsSesObservabilitySection;

// The mailer driver is chosen by VORTOS_MAILER_DRIVER in .env:
//   VORTOS_MAILER_DRIVER=ses   → real AWS SES (production)
//   VORTOS_MAILER_DRIVER=log   → writes to PSR logger (dev/staging, default)
//   VORTOS_MAILER_DRIVER=null  → silent drop, SesMailerFake in container (testing)
//
// Required env vars for the 'ses' driver:
//   AWS_SES_REGION
//   AWS_ACCESS_KEY_ID
//   AWS_SECRET_ACCESS_KEY
//   SES_FROM_ADDRESS
//
// All other settings have production-ready defaults. Only override what you need.
// For per-environment overrides create config/{env}/aws_ses.php.
//
// Service guarantees are explicit by injected type:
// - MailerInterface:
//     transactional outbox, active CommandBus/UnitOfWork transaction required.
// - StandaloneMailerInterface:
//     standalone async outbox, opens a short transaction for the outbox row only.
// - ImmediateMailerInterface:
//     direct provider call, no outbox.

return static function (VortosAwsSesConfig $config): void {
    $config
        ->driver($_ENV['VORTOS_MAILER_DRIVER'] ?? 'log')
        ->region($_ENV['AWS_SES_REGION'] ?? 'us-east-1')
        ->defaultFrom(
            $_ENV['SES_FROM_ADDRESS'] ?? '',
            $_ENV['SES_FROM_NAME'] ?? '',
        );

    // Uncomment for multi-region failover:
    // $config->fallbackRegion($_ENV['AWS_SES_FALLBACK_REGION'] ?? null);

    // Uncomment to enable SES open/click/bounce tracking via a configuration set:
    // $config->configurationSet($_ENV['SES_CONFIGURATION_SET'] ?? null);

    // AWS SDK tuning:
    // $config->awsClient()
    //     ->endpointOverride($_ENV['AWS_ENDPOINT'] ?? null)
    //     ->httpTimeout(2.0)
    //     ->maxRetries(3);

    // Outbox relay worker tuning:
    // $config->outbox()
    //     ->batchSize(50)
    //     ->maxDeliveryAttempts(5)
    //     ->sleepSecondsWhenEmpty(2);
    //
    // Install the outbox relay worker into Docker supervisor:
    // php bin/console vortos:worker:install --worker=aws-ses-outbox-relay

    // Rate limit — set to match your SES account's MaxSendRate from the AWS console:
    // $config->rateLimit()->maxSendRate(14);

    // Webhook path override (if the default /webhooks/aws/ses conflicts with your app):
    // $config->webhooks()->routePath('/webhooks/aws/ses');

    // Suppression behaviour — 'throw' (default) or 'strip':
    // $config->suppression()->onSuppressed('throw');

    // Package-level observability opt-outs. Section names are typed enums.
    // $config->observability()->logging(true)->tracing(true)->metrics(true);
    // $config->observability()
    //     ->disableLoggingFor(AwsSesObservabilitySection::Send)
    //     ->disableTracingFor(AwsSesObservabilitySection::Outbox)
    //     ->disableMetricsFor(AwsSesObservabilitySection::Webhook);
};
