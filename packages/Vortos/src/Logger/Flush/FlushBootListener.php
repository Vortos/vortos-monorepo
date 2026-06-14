<?php

declare(strict_types=1);

namespace Vortos\Logger\Flush;

use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Vortos\Http\Contract\TerminableMiddlewareInterface;
use Vortos\Http\Request;

/**
 * Wires FlushScheduler into both CLI and HTTP lifecycles.
 *
 *  - ConsoleEvents::COMMAND: arms the periodic/shutdown flush triggers —
 *    this is what makes long-running daemon commands (outbox relays, Kafka
 *    consumers) flush their logs without waiting for process exit.
 *  - ConsoleEvents::TERMINATE / ERROR: force-flush everything — covers
 *    normal short-lived command exits immediately.
 *  - HTTP terminate(): flush only sinks whose interval elapsed — avoids
 *    forcing an I/O flush on every single request in high-QPS workers.
 */
final class FlushBootListener implements TerminableMiddlewareInterface, EventSubscriberInterface
{
    public function __construct(private readonly FlushScheduler $scheduler) {}

    public static function getSubscribedEvents(): array
    {
        return [
            ConsoleEvents::COMMAND   => 'start',
            ConsoleEvents::TERMINATE => 'flushAll',
            ConsoleEvents::ERROR     => 'flushAll',
        ];
    }

    public function start(): void
    {
        $this->scheduler->start();
    }

    public function flushAll(): void
    {
        $this->scheduler->flushAll();
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->scheduler->flushDue();
    }
}
