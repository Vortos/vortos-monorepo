<?php

declare(strict_types=1);

use Monolog\Level;
use Vortos\Logger\Config\LogChannel;
use Vortos\Logger\DependencyInjection\VortosLoggingConfig;
use Vortos\Observability\Config\ObservabilityModule;

// This file configures the Vortos logger behaviour.
// The log destination (stderr vs file) and base level are environment-driven.
//
// Available channels:
//   App       — application logs (aliased to LoggerInterface, cannot be disabled)
//   Http      — request/response lifecycle
//   Cqrs      — CommandBus dispatch and handler execution
//   Messaging — Kafka, Outbox, DeadLetter events
//   Cache     — Redis get/set/delete (high volume in prod)
//   Security  — auth failures, token validation, authz denials
//   Query     — slow DB queries and DBAL/Mongo operations
//   Tooling   — local CLI/developer tooling commands
//
// For per-environment overrides create config/{env}/logging.php.

return static function (VortosLoggingConfig $config): void {
    // Service metadata is attached to structured log records.
    // Prefer immutable deployment values, not request/user data.
    //
    // $config->service(
    //     name: $_ENV['OTEL_SERVICE_NAME'] ?? $_ENV['APP_NAME'] ?? 'vortos-app',
    //     version: $_ENV['APP_VERSION'] ?? '',
    //     environment: $_ENV['APP_ENV'] ?? 'prod',
    // );

    // Silence high-volume channels that are useful in dev but noisy in prod.
    // Remove channels from this list to re-enable them.
    $config->disableChannel(
        // LogChannel::Cache,   // uncomment to silence cache get/set logs
        // LogChannel::Query,   // uncomment to silence DB query logs
    );

    // Or disable framework logs by observability module. Runtime modules map to
    // their safest framework channel; the App channel is never disabled.
    //
    // $config->disableModule(
    //     ObservabilityModule::Cache,
    //     ObservabilityModule::Persistence,
    //     ObservabilityModule::Make,
    // );

    // Per-channel minimum log level override.
    // By default: DEBUG in dev, ERROR for framework channels in prod.
    //
    // $config->channel(LogChannel::Security, Level::Warning);
    // $config->channel(LogChannel::Http, Level::Info);

    // Rotating log files (dev only by default).
    // Rotation creates date-stamped files and deletes the oldest after $maxFiles.
    // In prod, logs go to stderr — rotation has no effect there.
    //
    // $config->rotation(enabled: true, maxFiles: 14);

    // Buffer log records and flush once at request end.
    // Reduces disk I/O in FrankenPHP worker mode from N writes to 1 per request.
    // Default: enabled.
    //
    // $config->buffer(false); // disable if you need real-time log tailing

    // Redact sensitive values from record context and extra fields.
    // Default: enabled, with common auth/PII keys. Add project-specific keys here.
    //
    // $config->redaction(true, keys: ['card_number', 'billing_address']);

    // Add ECS/OpenTelemetry-style fields: service.name, service.version,
    // deployment.environment, event.dataset, log.logger.
    // Default: enabled.
    //
    // $config->structured(false);

    // Add bounded HTTP/user/tenant context without query strings or request bodies.
    // Default: enabled. Disable only for extremely high-throughput workers.
    //
    // $config->requestContext(false);

    // Introspection adds source file/class/function to log records.
    // Default: enabled in dev, disabled outside dev to avoid path leakage and overhead.
    //
    // $config->introspection(false);

    // Inject the active trace ID into every log record as 'trace_id'.
    // Requires vortos/vortos-tracing. No-ops silently when tracing is disabled.
    // Default: enabled.
    //
    // $config->correlationId(false);

    // Route errors to Sentry.
    // Requires sentry/sentry in your composer.json.
    // Missing packages fail at container compile time by default.
    //
    // $config->sentry(
    //     dsn: $_ENV['SENTRY_DSN'] ?? '',
    //     minLevel: Level::Error,
    // );

    // Route critical alerts to a Slack webhook.
    // This is a synchronous emergency sink; keep the level high and use a
    // central log pipeline or queued alerting for primary production alert flow.
    //
    // $config->slack(
    //     webhook: $_ENV['SLACK_LOG_WEBHOOK'] ?? '',
    //     minLevel: Level::Critical,
    // );

    // Route errors to an email address.
    // This is a synchronous emergency sink; prefer Sentry or log aggregation
    // for normal production incident routing.
    //
    // $config->email(
    //     to: $_ENV['LOG_ALERT_EMAIL'] ?? '',
    //     minLevel: Level::Error,
    // );

    // Set false only in local prototypes where optional alert packages may be
    // absent. Production should keep fail-fast behaviour.
    //
    // $config->failOnMissingIntegrations(true);
};
