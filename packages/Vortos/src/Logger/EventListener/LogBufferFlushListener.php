<?php

declare(strict_types=1);

namespace Vortos\Logger\EventListener;

use Monolog\Handler\BufferHandler;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Vortos\Http\Contract\TerminableMiddlewareInterface;
use Vortos\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class LogBufferFlushListener implements TerminableMiddlewareInterface, EventSubscriberInterface
{
    public function __construct(private readonly BufferHandler $handler) {}

    public static function getSubscribedEvents(): array
    {
        return [
            ConsoleEvents::TERMINATE => 'flush',
            ConsoleEvents::ERROR => 'flush',
        ];
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->handler->flush();
    }

    public function flush(): void
    {
        $this->handler->flush();
    }
}
