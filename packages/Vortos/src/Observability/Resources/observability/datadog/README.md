# Vortos Datadog Templates

Use these examples when Vortos metrics are sent through StatsD/DogStatsD, logs are collected from JSON stdout/stderr, and traces are sent through OpenTelemetry Collector or Datadog Agent OTLP intake.

Typical runtime config:

- Metrics: `MetricsAdapter::StatsD`
- Logs: Vortos logger JSON to stdout/stderr, collected by Datadog Agent
- Traces: Vortos tracing OpenTelemetry adapter to Datadog Agent or OTel Collector

Import the JSON files through Datadog API/Terraform/provider tooling and adjust tags, service names, and thresholds per environment.

For outbox and DLQ gauges, run `php bin/console vortos:metrics:collect` on a
single scheduled worker. This emits point-in-time DogStatsD gauges without doing
database aggregation on normal HTTP requests.
