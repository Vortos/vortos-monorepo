<?php

declare(strict_types=1);

namespace Vortos\Metrics\Schedule;

use Vortos\Domain\Command\CommandInterface;
use Vortos\Scheduler\Security\Attribute\SchedulableCommand;

/**
 * Fired on a cadence by {@see OperationalMetricsCollectSchedule} to refresh the point-in-time
 * operational gauges (outbox backlog, DLQ backlog, oldest-pending/failed age).
 *
 * Those gauges are *pull-shaped*: they are a SELECT against the messaging tables, not something the
 * request path can increment. Under the Prometheus adapter the scrape itself drives them, because
 * {@see \Vortos\Metrics\Http\MetricsController} calls every tagged collector before rendering. Under
 * a PUSH adapter (OpenTelemetry OTLP, StatsD) nothing scrapes, so without a cadence the gauges are
 * defined, wired, tested — and permanently absent from the backend. This schedule is what closes
 * that gap, which is why it is registered only for push adapters.
 *
 * Carries no payload: a gauge sample is only meaningful as "the value right now", so the handler
 * resolves everything at fire time. Idempotent by construction — re-collecting simply re-observes
 * current state.
 */
#[SchedulableCommand]
final readonly class CollectOperationalMetricsCommand implements CommandInterface
{
    public function idempotencyKey(): ?string
    {
        return null;
    }
}
