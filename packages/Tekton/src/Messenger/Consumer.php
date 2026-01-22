<?php

namespace Fortizan\Tekton\Messenger;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Symfony\Component\Messenger\Worker;

class Consumer
{
    public function __construct(
        private TransportInterface $transport,
        private ContainerInterface $container,
        private array $globalHandlerMap = [],
        private ?EventDispatcherInterface $eventDispatcher = null
    ) {}

    public function run(string $groupId): void
    {
        $bus = $this->createBusForGroup($groupId);

        $worker = new Worker(
            receivers: [$this->transport],
            bus: $bus,
            eventDispatcher: $this->eventDispatcher
        );

        $worker->run();
    }

    private function createBusForGroup(string $groupId): ?MessageBusInterface
    {
        if (!isset($this->globalHandlerMap[$groupId])) {
            throw new \RuntimeException("No handlers registered for group: $groupId");
        }

        $handlers = [];
        foreach ($this->globalHandlerMap[$groupId] as $eventClass => $serviceIds) {
            foreach ($serviceIds as $id => $metadata) {
                $handlers[$eventClass][] = $this->container->get($id);
            }
        }

        $middleware = [
            new HandleMessageMiddleware(new HandlersLocator($handlers))
        ];

        return new MessageBus($middleware);
    }
}
