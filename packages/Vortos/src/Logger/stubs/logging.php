<?php

declare(strict_types=1);

use Monolog\Level;
use Vortos\Logger\Config\LogChannel;
use Vortos\Logger\DependencyInjection\VortosLoggingConfig;
use Vortos\Observability\Config\ObservabilityModule;

// This file configures the Vortos logging pipeline.
//
// Pipeline model: every LogChannel routes its records to one or more named
// *sinks*. A sink is a destination (file, stream, syslog, or a custom
// handler service) with its own level, buffer policy, rotation/retention,
// sampling, and optional hash-chaining.
//
// By default every channel gets its own same-named sink:
//   dev:  file  var/log/{channel}.log — daily rotation, gzip, 14 files / 30 days / 1GB
//   prod: stream php://stderr, JSON
//
// Security and Audit channels are write-through by default (no buffering —
// zero loss on crash). Audit additionally hash-chains every record and
// enforces a 365-day minimum retention floor for file sinks.
//
// Channels: App (cannot be disabled, aliased to LoggerInterface), Http, Cqrs,
// Messaging, Cache, Security, Audit, Query, Tooling — plus any custom channel
// name you register via $config->channel('my-channel').
//
// For per-environment overrides create config/{env}/logging.php.

return static function (VortosLoggingConfig $config): void {
    // Service metadata is attached to structured log records.
    //
    // $config->service(
    //     name: $_ENV['OTEL_SERVICE_NAME'] ?? $_ENV['APP_NAME'] ?? 'vortos-app',
    //     version: $_ENV['APP_VERSION'] ?? '',
    //     environment: $_ENV['APP_ENV'] ?? 'prod',
    // );

    // Silence a channel entirely — its logger discards all records.
    // The App channel cannot be disabled.
    //
    // $config->channel(LogChannel::Cache)->disable();
    // $config->channel(LogChannel::Query)->disable();

    // Or disable framework logs by observability module. Runtime modules map
    // to their safest framework channel; the App channel is never disabled.
    //
    // $config->disableModule(
    //     ObservabilityModule::Cache,
    //     ObservabilityModule::Persistence,
    //     ObservabilityModule::Make,
    // );

    // Per-channel minimum log level override.
    // By default: DEBUG in dev, WARNING for App / ERROR for other channels in prod.
    //
    // $config->channel(LogChannel::Security)->level(Level::Warning);
    // $config->channel(LogChannel::Http)->level(Level::Info);

    // Configure a sink's destination, rotation, buffering, sampling.
    //
    // $config->sink(LogChannel::Cache->value)
    //     ->toFile('cache.log')
    //     ->sample(100)                 // only ~1/100 records
    //     ->rotation(maxFiles: 7, maxAgeDays: 14);
    //
    // // Stream everything to stderr (no local files), even in dev:
    // foreach (LogChannel::cases() as $channel) {
    //     $config->sink($channel->value)->toStream('php://stderr');
    // }
    //
    // // Write to syslog:
    // $config->sink(LogChannel::Security->value)->toSyslog('myapp');

    // Fan a channel out to additional sinks — e.g. ship security/audit
    // records to a SIEM in addition to the local file.
    //
    // $config->sink('siem')->customHandler('app.logging.siem_handler');
    // $config->channel(LogChannel::Security)->alsoRouteTo('siem');
    // $config->channel(LogChannel::Audit)->alsoRouteTo('siem');

    // The Audit sink enforces >=365 days of file retention. Only override
    // this if you understand the compliance implications:
    //
    // $config->sink(LogChannel::Audit->value)
    //     ->rotation(maxAgeDays: 90)
    //     ->acknowledgeComplianceRisk();

    // Default flush interval (seconds) for batched (non-write-through) sinks.
    // Buffered records are guaranteed to flush within this window even in
    // long-running daemon/worker processes. Default: 2.
    //
    // $config->flushInterval(5);

    // Redact sensitive values from record context/extra (key-based) and from
    // messages/values (pattern-based: JWTs, AWS keys, auth headers, card
    // numbers, emails). Default: enabled, with common auth/PII keys.
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

    // Route critical alerts to a Slack webhook (added to every non-disabled channel).
    // This is a synchronous emergency sink; keep the level high and use a
    // central log pipeline or queued alerting for primary production alert flow.
    //
    // $config->slack(
    //     webhook: $_ENV['SLACK_LOG_WEBHOOK'] ?? '',
    //     minLevel: Level::Critical,
    // );

    // Route errors to an email address (added to every non-disabled channel).
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
