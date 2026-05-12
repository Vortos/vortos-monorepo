# Vortos New Relic Templates

Use these examples when Vortos logs are collected from JSON stdout/stderr, traces are sent through OpenTelemetry OTLP, and metrics are ingested through Prometheus remote write, StatsD integration, or OpenTelemetry Collector.

The JSON and YAML files are starter assets for API/Terraform workflow. Adjust account ids, entity names, service names, and thresholds per environment.

If metrics reach New Relic through StatsD or an OpenTelemetry Collector push
pipeline, schedule `php bin/console vortos:metrics:collect` on one worker so
outbox and DLQ gauges are emitted consistently.
