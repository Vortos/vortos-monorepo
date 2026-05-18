<?php

declare(strict_types=1);

namespace Vortos\Messaging\Dev;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Vortos\Domain\Event\DomainEventInterface;
use Vortos\Messaging\Contract\EventBusInterface;
use Vortos\Messaging\Registry\HandlerRegistry;

/**
 * Dev/test decorator that synchronously invokes projection handlers immediately
 * after each event is dispatched to the real EventBus.
 *
 * In production, projections are updated asynchronously: the event is written to
 * the outbox, relayed to Kafka, consumed by a worker, and then the projection
 * handler runs. In tests and local dev this latency makes it impossible to assert
 * on read-model state immediately after a command — you would need arbitrary sleeps
 * or polling.
 *
 * This decorator solves that: after the real dispatch() returns, it looks up all
 * projection handlers for the event across every consumer and calls them in-process
 * synchronously. The read model is updated before dispatch() returns, so tests can
 * assert on it immediately.
 *
 * ## How it is registered
 *
 * MessagingExtension registers this as a Symfony decorator for EventBusInterface
 * in dev and test environments only. Set VORTOS_SYNC_PROJECTIONS=false to opt out
 * (useful when you want to test real Kafka flow end-to-end in dev).
 *
 * ## Production
 *
 * This class is NEVER active in production — it is gated by kernel.env and the
 * VORTOS_SYNC_PROJECTIONS env variable. No performance or correctness impact
 * on production deployments.
 *
 * ## Error handling
 *
 * Projection errors are logged and swallowed — the same way a real Kafka consumer
 * handles transient failures before DLQ. This prevents a broken projection from
 * masking the real domain error when a command fails.
 */
final class SyncProjectionEventBusDecorator implements EventBusInterface
{
    public function __construct(
        private readonly EventBusInterface $inner,
        private readonly HandlerRegistry $handlerRegistry,
        private readonly ServiceLocator $handlerLocator,
        private readonly LoggerInterface $logger,
    ) {}

    public function dispatchBatch(DomainEventInterface ...$events): void
    {
        foreach ($events as $event) {
            $this->dispatch($event);
        }
    }

    public function dispatch(DomainEventInterface $event): void
    {
        $this->inner->dispatch($event);

        $eventClass = get_class($event);

        foreach ($this->handlerRegistry->allConsumers() as $consumer) {
            $descriptors = $this->handlerRegistry->getHandlers($consumer, $eventClass);

            foreach ($descriptors as $descriptor) {
                if (!($descriptor['isProjection'] ?? false)) {
                    continue;
                }

                $serviceId = $descriptor['serviceId'];
                $method    = $descriptor['method'] ?? '__invoke';

                if (!$this->handlerLocator->has($serviceId)) {
                    continue;
                }

                try {
                    $handler = $this->handlerLocator->get($serviceId);
                    $handler->$method($event);
                } catch (\Throwable $e) {
                    $this->logger->warning('SyncProjectionEventBusDecorator: projection failed (swallowed)', [
                        'consumer'   => $consumer,
                        'handler'    => $serviceId,
                        'event'      => $eventClass,
                        'error'      => $e->getMessage(),
                    ]);
                }
            }
        }
    }
}
