<?php

declare(strict_types=1);

namespace Vortos\Logger\EventListener;

use Monolog\Handler\BufferHandler;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class LogBufferFlushListener implements EventSubscriberInterface
{
    public function __construct(private readonly BufferHandler $handler) {}

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
        $this->handler->flush();
    }
}
