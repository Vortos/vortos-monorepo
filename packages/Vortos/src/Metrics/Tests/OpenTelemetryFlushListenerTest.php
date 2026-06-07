<?php

declare(strict_types=1);

namespace Vortos\Metrics\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\ConsoleEvents;
use Vortos\Metrics\Adapter\OpenTelemetryFlushListener;
use Vortos\Metrics\Contract\FlushableMetricsInterface;

final class OpenTelemetryFlushListenerTest extends TestCase
{
    public function test_flushes_via_terminate(): void
    {
        $metrics = new class implements FlushableMetricsInterface {
            public int $flushes = 0;
            public function flush(): void
            {
                $this->flushes++;
            }
        };

        $request  = \Vortos\Http\Request::create('/');
        $response = new \Vortos\Http\Response();

        $listener = new OpenTelemetryFlushListener($metrics);
        $listener->terminate($request, $response);
        $listener->terminate($request, $response);

        $this->assertSame(2, $metrics->flushes);
    }

    public function test_subscribes_to_console_events(): void
    {
        $events = OpenTelemetryFlushListener::getSubscribedEvents();
        $this->assertArrayHasKey(ConsoleEvents::TERMINATE, $events);
        $this->assertArrayHasKey(ConsoleEvents::ERROR, $events);
    }
}
