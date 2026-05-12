<?php

declare(strict_types=1);

namespace Vortos\Tests\Metrics;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\HttpKernel\KernelEvents;
use Vortos\Metrics\Adapter\OpenTelemetryFlushListener;
use Vortos\Metrics\Contract\FlushableMetricsInterface;

final class OpenTelemetryFlushListenerTest extends TestCase
{
    public function test_flushes_on_lifecycle_events_without_shutdown(): void
    {
        $metrics = new class implements FlushableMetricsInterface {
            public int $flushes = 0;
            public function flush(): void
            {
                $this->flushes++;
            }
        };

        $listener = new OpenTelemetryFlushListener($metrics);
        $listener->flush();
        $listener->flush();

        $this->assertSame(2, $metrics->flushes);
        $this->assertArrayHasKey(KernelEvents::TERMINATE, OpenTelemetryFlushListener::getSubscribedEvents());
        $this->assertArrayHasKey(ConsoleEvents::TERMINATE, OpenTelemetryFlushListener::getSubscribedEvents());
    }
}
