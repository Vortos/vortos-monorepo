<?php

declare(strict_types=1);

namespace Vortos\Analytics\Runtime;

use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Throwable;
use Vortos\Analytics\AnalyticsInterface;

/**
 * Flushes buffered analytics when a CONSOLE process ends, so a command, a Kafka consumer or
 * a scheduled job ships what it captured instead of buffering until the process exits.
 *
 * The HTTP half of the same job lives in {@see HttpTerminateFlush}, which is separate on
 * purpose: it depends on `vortos-http`, and analytics must stay usable in an application
 * that has no HTTP layer at all.
 *
 * ## This class used to subscribe to KernelEvents::TERMINATE, and that never ran
 *
 * `Vortos\Http\Kernel::terminate()` iterates `$this->terminableMiddleware` and dispatches no
 * events, so an HTTP subscriber on `KernelEvents::TERMINATE` was dead code. The failure was
 * invisible because {@see BatchingAnalytics} ALSO flushes on its own once the buffer reaches
 * `flushAt` (100 by default) — so a busy period delivered in round hundreds and a quiet one
 * delivered nothing. In production that read as exactly 200 events over two weeks of traffic
 * and then silence, with everything after sitting in a long-lived worker's buffer waiting for
 * a hundredth sibling that never came.
 */
final class FlushOnTerminateSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly AnalyticsInterface $analytics) {}

    public function onTerminate(): void
    {
        try {
            $this->analytics->flush();
        } catch (Throwable) {
            // Intentionally swallowed: a shutdown flush must never throw.
        }
    }

    /** @return array<string,string> */
    public static function getSubscribedEvents(): array
    {
        return [
            ConsoleEvents::TERMINATE => 'onTerminate',
            ConsoleEvents::ERROR => 'onTerminate',
        ];
    }
}
