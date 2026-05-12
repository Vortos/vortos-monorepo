<?php

declare(strict_types=1);

namespace Vortos\Metrics\AutoInstrumentation;

use Vortos\Domain\Event\DomainEventInterface;
use Vortos\Messaging\Contract\EventBusInterface;
use Vortos\Metrics\Telemetry\FrameworkTelemetry;
use Vortos\Observability\Config\ObservabilityModule;
use Vortos\Observability\Telemetry\FrameworkMetric;
use Vortos\Observability\Telemetry\FrameworkMetricLabels;
use Vortos\Observability\Telemetry\MetricLabel;
use Vortos\Observability\Telemetry\MetricLabelValue;

/**
 * Decorates EventBusInterface to record per-event metrics.
 *
 * ## Metrics recorded
 *
 *   vortos_messaging_events_dispatched_total{event}  — counter (all dispatches)
 *   vortos_messaging_event_failures_total{event}     — counter (exceptions only)
 *   vortos_messaging_event_duration_ms{event}        — histogram (dispatch time)
 *
 * ## Label value
 *
 *   'event' uses the short class name (e.g. 'UserRegisteredEvent').
 */
final class MessagingMetricsDecorator implements EventBusInterface
{
    public function __construct(
        private readonly EventBusInterface $inner,
        private readonly FrameworkTelemetry $telemetry,
    ) {}

    public function dispatch(DomainEventInterface $event): void
    {
        $eventName = substr(strrchr(get_class($event), '\\') ?: get_class($event), 1);
        $start     = hrtime(true);

        $labels = FrameworkMetricLabels::of(MetricLabelValue::of(MetricLabel::Event, $eventName));
        $this->telemetry->increment(ObservabilityModule::Messaging, FrameworkMetric::MessagingEventsDispatchedTotal, $labels);

        try {
            $this->inner->dispatch($event);
        } catch (\Throwable $e) {
            $this->telemetry->increment(ObservabilityModule::Messaging, FrameworkMetric::MessagingEventFailuresTotal, $labels);
            throw $e;
        } finally {
            $durationMs = (hrtime(true) - $start) / 1_000_000;
            $this->telemetry->observe(ObservabilityModule::Messaging, FrameworkMetric::MessagingEventDurationMs, $labels, $durationMs);
        }
    }

    public function dispatchBatch(DomainEventInterface ...$events): void
    {
        foreach ($events as $event) {
            $this->dispatch($event);
        }
    }
}
