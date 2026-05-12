# Vortos Observability Templates

This module publishes optional starter assets for common observability stacks. It does not export telemetry, call external services, or run in the request path.

Vortos runtime modules emit standard signals:

- metrics through Prometheus or StatsD
- traces through OpenTelemetry OTLP
- logs as structured JSON to stdout/stderr
- health endpoints through the foundation module

The templates help teams bootstrap dashboards and alert rules for those signals.

Messaging applications also export operational gauges for the transactional
outbox and dead-letter queue:

- `vortos_outbox_backlog_size{transport,status}`
- `vortos_outbox_oldest_pending_age_seconds{transport}`
- `vortos_dlq_backlog_size{transport,event}`
- `vortos_dlq_oldest_failed_age_seconds{transport}`

With Prometheus these are refreshed during `/metrics` scrapes. With push-style
backends such as StatsD, schedule `php bin/console vortos:metrics:collect` from
one worker per environment so gauges are emitted without adding work to normal
request handling.

## Commands

```bash
php bin/console vortos:observability:list
php bin/console vortos:observability:publish --stack=grafana-oss
php bin/console vortos:observability:publish --stack=datadog
php bin/console vortos:observability:publish --stack=newrelic
```

Published files are written under `observability/`.

Use `--dry-run` to preview and `--force` to overwrite existing files.

## Stacks

- `prometheus`: recording and alert rules
- `grafana`: Grafana dashboard JSON
- `alertmanager`: Alertmanager routing example
- `grafana-oss`: Prometheus + Grafana + Alertmanager
- `datadog`: Datadog dashboard and monitor examples
- `newrelic`: New Relic dashboard and alert examples

All thresholds and notification routes are examples. Review and tune them per environment before production use.
