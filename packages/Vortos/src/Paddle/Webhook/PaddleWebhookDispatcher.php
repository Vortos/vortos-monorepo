<?php

declare(strict_types=1);

namespace Vortos\Paddle\Webhook;

use Psr\Log\LoggerInterface;
use Vortos\Paddle\Webhook\Event\PaddleWebhookEvent;

final class PaddleWebhookDispatcher
{
    /** @param PaddleWebhookHandlerInterface[] $handlers */
    public function __construct(
        private readonly array           $handlers,
        private readonly LoggerInterface $logger,
    ) {}

    public function dispatch(PaddleWebhookEvent $event): void
    {
        foreach ($this->handlers as $handler) {
            if ($handler->handles() !== $event->eventType) {
                continue;
            }

            try {
                $handler->handle($event);
            } catch (\Throwable $e) {
                $this->logger->error('Paddle webhook handler failed', [
                    'handler'    => $handler::class,
                    'event_type' => $event->eventType,
                    'event_id'   => $event->eventId,
                    'error'      => $e->getMessage(),
                ]);
            }
        }
    }
}
