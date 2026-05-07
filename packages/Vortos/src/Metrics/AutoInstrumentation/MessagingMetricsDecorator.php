<?php

declare(strict_types=1);

namespace Vortos\Metrics\AutoInstrumentation;

use Vortos\Domain\Event\DomainEventInterface;
use Vortos\Messaging\Contract\EventBusInterface;
use Vortos\Metrics\Contract\MetricsInterface;

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
    private const DURATION_BUCKETS = [1, 5, 10, 25, 50, 100, 250, 500, 1000];

    public function __construct(
        private readonly EventBusInterface $inner,
        private readonly MetricsInterface $metrics,
    ) {}

    public function dispatch(DomainEventInterface $event): void
    {
        $eventName = substr(strrchr(get_class($event), '\\') ?: get_class($event), 1);
        $start     = hrtime(true);

        $this->metrics->counter('messaging_events_dispatched_total', ['event' => $eventName])->increment();

        try {
            $this->inner->dispatch($event);
        } catch (\Throwable $e) {
            $this->metrics->counter('messaging_event_failures_total', ['event' => $eventName])->increment();
            throw $e;
        } finally {
            $durationMs = (hrtime(true) - $start) / 1_000_000;
            $this->metrics->histogram('messaging_event_duration_ms', self::DURATION_BUCKETS, [
                'event' => $eventName,
            ])->observe($durationMs);
        }
    }

    public function dispatchBatch(DomainEventInterface ...$events): void
    {
        foreach ($events as $event) {
            $this->dispatch($event);
        }
    }
}
