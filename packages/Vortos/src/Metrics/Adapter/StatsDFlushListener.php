<?php

declare(strict_types=1);

namespace Vortos\Metrics\Adapter;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Flushes the StatsD send buffer after each request.
 *
 * In FrankenPHP worker mode the PHP process is long-lived — StatsDMetrics::__destruct()
 * never runs between requests. This listener calls flush() on kernel.terminate
 * (after the response is sent) so metrics are dispatched once per request with
 * minimal syscall overhead.
 *
 * Only registered by MetricsExtension when adapter = StatsD.
 */
final class StatsDFlushListener implements EventSubscriberInterface
{
    public function __construct(private readonly StatsDMetrics $metrics) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::TERMINATE => 'onTerminate'];
    }

    public function onTerminate(): void
    {
        $this->metrics->flush();
    }
}
