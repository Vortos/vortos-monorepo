# Vortos Observability

Two cooperating subsystems:

1. **Telemetry destination seam** (`Sink/`, `Collector/`, `Buffer/`, `Heartbeat/`,
   `Driver/`) — the §12.4 "emitting ≠ monitoring" plane. The app emits OTLP to a
   **local** OpenTelemetry Collector sidecar; the collector batches, disk-buffers and
   retries to an **off-host** backend. The only swap point is a driver
   (`MetricsSinkInterface` / `ErrorSinkInterface`); switching backends never touches
   app code. See "Telemetry seam" below.
2. **Template publisher** (`Service/`, `Command/`, `Resources/`) — optional starter
   dashboards/alert rules for common stacks. It does not export telemetry, call
   external services, or run in the request path.

## Telemetry seam (collector + off-host sinks)

- **Drivers** (the only place a backend name appears): `grafana` (OTLP metrics/traces/
  logs, off-host), `glitchtip` (error sink, disk-buffered), `null` (explicit no-op).
  Vendor SDK drivers (`datadog`, `sentry`, …) are deferred split packages.
- **Collector config** is generated for the selected sink — loopback-only OTLP
  receiver, `memory_limiter` + `batch`, a cardinality deny-list, and a `file_storage`
  persistent queue (`retry_on_failure` + `sending_queue`) so a backend blip buffers to
  disk and drains on recovery. Backend credentials are referenced via `${env:...}` —
  never inlined into the committed config.
- **Error sink** spools captured errors to a **bounded, crash-safe** on-disk queue
  (`BoundedSpool`: byte-capped, drop-oldest + counter, CRC-checked, atomic rewrite);
  `capture()` never blocks the request path and never throws. Errors are PII-scrubbed
  by construction (`MessageScrubber` / `CapturedError`).
- **Dead-man heartbeat** (`HttpHeartbeatEmitter`): the app pushes a periodic check-in
  to an external monitor; *absence* pages (detected off-host) — the only detector that
  catches "host dead AND its monitoring dead."

### Telemetry-seam commands

```bash
php bin/console vortos:observability:collector --sink=grafana   # generate sidecar config
php bin/console vortos:observability:collector --dry-run        # preview
php bin/console vortos:observability:heartbeat --status=success # dead-man check-in (cron, ~60s)
```

### Configuration (env)

| Var | Default | Purpose |
|-----|---------|---------|
| `OBSERVABILITY_METRICS_SINK` | `grafana` | Selected metrics sink driver key |
| `OBSERVABILITY_ERROR_SINK` | `glitchtip` | Selected error sink driver key |
| `OBSERVABILITY_GRAFANA_OTLP_HOST` | — | Off-host OTLP gateway host |
| `OBSERVABILITY_GRAFANA_OTLP_HEADERS` | — | Auth header (resolved by the collector at runtime) |
| `OBSERVABILITY_GLITCHTIP_DSN` | — | Error backend ingest URL (read at use time, never logged) |
| `OBSERVABILITY_SPOOL_DIR` | system temp | Error spool directory |
| `OBSERVABILITY_SPOOL_MAX_BYTES` | 256 MiB | Error spool byte cap (drop-oldest past this) |
| `OBSERVABILITY_HEARTBEAT_URL` | — | External dead-man monitor base URL |

---

# Observability Templates

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
