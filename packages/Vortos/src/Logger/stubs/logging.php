<?php

declare(strict_types=1);

use Monolog\Level;
use Vortos\Logger\Config\LogChannel;
use Vortos\Logger\DependencyInjection\VortosLoggingConfig;

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
//
// For per-environment overrides create config/{env}/logging.php.

return static function (VortosLoggingConfig $config): void {
    // Silence high-volume channels that are useful in dev but noisy in prod.
    // Remove channels from this list to re-enable them.
    $config->disableChannel(
        // LogChannel::Cache,   // uncomment to silence cache get/set logs
        // LogChannel::Query,   // uncomment to silence DB query logs
    );

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

    // Inject the active trace ID into every log record as 'trace_id'.
    // Requires vortos/vortos-tracing. No-ops silently when tracing is disabled.
    // Default: enabled.
    //
    // $config->correlationId(false);

    // Route errors to Sentry.
    // Requires sentry/sentry in your composer.json.
    //
    // $config->sentry(
    //     dsn: $_ENV['SENTRY_DSN'] ?? '',
    //     minLevel: Level::Error,
    // );

    // Route critical alerts to a Slack webhook.
    //
    // $config->slack(
    //     webhook: $_ENV['SLACK_LOG_WEBHOOK'] ?? '',
    //     minLevel: Level::Critical,
    // );

    // Route errors to an email address.
    //
    // $config->email(
    //     to: $_ENV['LOG_ALERT_EMAIL'] ?? '',
    //     minLevel: Level::Error,
    // );
};
