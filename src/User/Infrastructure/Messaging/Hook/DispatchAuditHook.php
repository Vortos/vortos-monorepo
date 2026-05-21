<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Messaging\Hook;

use Psr\Log\LoggerInterface;
use Vortos\Domain\Event\EventEnvelope;
use Vortos\Messaging\Hook\Attribute\BeforeDispatch;

#[BeforeDispatch(priority: 10)]
final class DispatchAuditHook
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(EventEnvelope $envelope): void
    {
        $this->logger->info('Event dispatched', [
            'event'       => $envelope->payloadType,
            'aggregateId' => $envelope->aggregateId,
            'occurredAt'  => $envelope->occurredAt->format(\DateTimeInterface::ATOM),
            'version'     => $envelope->aggregateVersion,
        ]);
    }
}
