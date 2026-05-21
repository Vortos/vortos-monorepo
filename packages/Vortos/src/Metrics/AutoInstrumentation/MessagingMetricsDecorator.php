<?php

declare(strict_types=1);

namespace Vortos\Metrics\AutoInstrumentation;

use Vortos\Domain\Event\EventEnvelope;
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
 *   'event' uses the short class name of the envelope's payload type
 *   (e.g. 'UserRegistered').
 */
final class MessagingMetricsDecorator implements EventBusInterface
{
    public function __construct(
        private readonly EventBusInterface $inner,
        private readonly FrameworkTelemetry $telemetry,
    ) {}

    public function dispatch(EventEnvelope $envelope): void
    {
        $payloadType = $envelope->payloadType;
        $eventName = substr(strrchr($payloadType, '\\') ?: $payloadType, 1);
        $start     = hrtime(true);

        $labels = FrameworkMetricLabels::of(MetricLabelValue::of(MetricLabel::Event, $eventName));
        $this->telemetry->increment(ObservabilityModule::Messaging, FrameworkMetric::MessagingEventsDispatchedTotal, $labels);

        try {
            $this->inner->dispatch($envelope);
        } catch (\Throwable $e) {
            $this->telemetry->increment(ObservabilityModule::Messaging, FrameworkMetric::MessagingEventFailuresTotal, $labels);
            throw $e;
        } finally {
            $durationMs = (hrtime(true) - $start) / 1_000_000;
            $this->telemetry->observe(ObservabilityModule::Messaging, FrameworkMetric::MessagingEventDurationMs, $labels, $durationMs);
        }
    }

    public function dispatchBatch(EventEnvelope ...$envelopes): void
    {
        foreach ($envelopes as $envelope) {
            $this->dispatch($envelope);
        }
    }
}
