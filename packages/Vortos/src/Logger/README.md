# Vortos Logger

The logger module provides PSR-3/Monolog logging with secure production defaults and explicit enterprise controls.

## Defaults

- `app`, `http`, `cqrs`, `messaging`, `cache`, `security`, and `query` channels are registered.
- Production logs are JSON records on stderr.
- Development logs use local rotating files.
- Log buffering is enabled and flushed at HTTP terminate, console terminate, and console error events.
- Trace correlation is enabled when the tracing module is present.
- Sensitive keys are redacted from `context` and `extra`.
- Structured fields are attached: `service.name`, `service.version`, `deployment.environment`, `event.dataset`, and `log.logger`.
- Request context is bounded to method, path, client address, user agent, tenant id, and user id.
- Introspection is enabled only in `dev`; it is disabled outside `dev` to avoid source path leakage and extra stack work.

## Configuration

```php
use Monolog\Level;
use Vortos\Logger\Config\LogChannel;
use Vortos\Logger\DependencyInjection\VortosLoggingConfig;

return static function (VortosLoggingConfig $config): void {
    $config
        ->service(
            name: $_ENV['OTEL_SERVICE_NAME'] ?? $_ENV['APP_NAME'] ?? 'checkout-api',
            version: $_ENV['APP_VERSION'] ?? '',
            environment: $_ENV['APP_ENV'] ?? 'prod',
        )
        ->redaction(true, keys: ['card_number', 'billing_address'])
        ->structured(true)
        ->requestContext(true)
        ->correlationId(true)
        ->buffer(true)
        ->channel(LogChannel::Security, Level::Warning);
};
```

## Redaction

Redaction is enabled by default for common auth and PII keys such as `password`, `token`, `authorization`, `api_key`, `email`, `phone`, and `ssn`.

Add domain-specific keys with `redaction(true, keys: [...])`. Redaction is recursive with a bounded depth, so large or cyclic payloads do not create unbounded work.

Do not log request bodies, full headers, payment data, access tokens, refresh tokens, or raw identity provider payloads. Prefer stable ids and event names.

## Alert Sinks

Sentry is supported as an error sink. If Sentry is configured but `sentry/sentry` is not installed, container compilation fails by default. This prevents a production deployment that appears configured but silently drops incidents.

Slack and email handlers are synchronous emergency sinks. Keep their minimum level high, such as `Critical`, and use Sentry, OpenTelemetry logs, or centralized log aggregation for primary production alerting.

```php
$config->sentry(dsn: $_ENV['SENTRY_DSN'] ?? '', minLevel: Level::Error);
$config->slack(webhook: $_ENV['SLACK_LOG_WEBHOOK'] ?? '', minLevel: Level::Critical);
$config->email(to: $_ENV['LOG_ALERT_EMAIL'] ?? '', minLevel: Level::Critical);
```

Set `failOnMissingIntegrations(false)` only in local prototypes.

