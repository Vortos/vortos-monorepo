<?php

declare(strict_types=1);

namespace Vortos\Paddle\Webhook;

use Vortos\Paddle\Webhook\Event\PaddleWebhookEvent;

/**
 * Routes a Paddle webhook event to the handlers registered for its type.
 *
 * Exceptions PROPAGATE — the caller owns failure policy. In production the
 * caller is PaddleInboxWorker, which retries with backoff and dead-letters
 * after exhaustion; swallowing here would turn handler failures into
 * silently lost webhooks.
 */
final class PaddleWebhookDispatcher
{
    /** @param PaddleWebhookHandlerInterface[] $handlers */
    public function __construct(
        private readonly array $handlers,
    ) {}

    /**
     * Handlers registered for the given Paddle event type, in registration order.
     *
     * @return PaddleWebhookHandlerInterface[]
     */
    public function handlersFor(string $eventType): array
    {
        return array_values(array_filter(
            $this->handlers,
            static fn(PaddleWebhookHandlerInterface $handler) => $handler->handles() === $eventType,
        ));
    }

    /**
     * Dispatches to all matching handlers sequentially. First failure throws.
     */
    public function dispatch(PaddleWebhookEvent $event): void
    {
        foreach ($this->handlersFor($event->eventType) as $handler) {
            $handler->handle($event);
        }
    }
}
