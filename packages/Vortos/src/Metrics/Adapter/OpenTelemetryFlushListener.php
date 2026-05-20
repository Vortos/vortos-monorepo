<?php

declare(strict_types=1);

namespace Vortos\Metrics\Adapter;

use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Vortos\Http\Contract\TerminableMiddlewareInterface;
use Vortos\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Vortos\Metrics\Contract\FlushableMetricsInterface;

final class OpenTelemetryFlushListener implements TerminableMiddlewareInterface, EventSubscriberInterface
{
    public function __construct(private readonly FlushableMetricsInterface $metrics) {}

    public static function getSubscribedEvents(): array
    {
        return [
            ConsoleEvents::TERMINATE => 'flush',
            ConsoleEvents::ERROR => 'flush',
        ];
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->metrics->flush();
    }

    public function flush(): void
    {
        $this->metrics->flush();
    }
}
