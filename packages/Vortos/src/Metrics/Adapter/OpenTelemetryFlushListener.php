<?php

declare(strict_types=1);

namespace Vortos\Metrics\Adapter;

use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Vortos\Metrics\Contract\FlushableMetricsInterface;

final class OpenTelemetryFlushListener implements EventSubscriberInterface
{
    public function __construct(private readonly FlushableMetricsInterface $metrics) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::TERMINATE => 'flush',
            ConsoleEvents::TERMINATE => 'flush',
            ConsoleEvents::ERROR => 'flush',
        ];
    }

    public function flush(): void
    {
        $this->metrics->flush();
    }
}
