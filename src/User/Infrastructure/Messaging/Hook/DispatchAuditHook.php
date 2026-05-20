<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Messaging\Hook;

use Psr\Log\LoggerInterface;
use Vortos\Domain\Event\DomainEventInterface;
use Vortos\Messaging\Hook\Attribute\BeforeDispatch;

#[BeforeDispatch(priority: 10)]
final class DispatchAuditHook
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(DomainEventInterface $event): void
    {
        $this->logger->info('Event dispatched', [
            'event'       => get_class($event),
            'aggregateId' => $event->aggregateId(),
            'occurredAt'  => $event->occurredAt()->format(\DateTimeInterface::ATOM),
            'version'     => $event->eventVersion(),
        ]);
    }
}
