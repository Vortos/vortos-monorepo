<?php

declare(strict_types=1);

namespace App\User\Application\Projection;

use App\User\Domain\Event\UserCreatedEvent;
use Vortos\Cqrs\Attribute\AsProjectionHandler;
use Psr\Log\LoggerInterface;

#[AsProjectionHandler(consumer: 'user.events', handlerId: 'user.read-model')]
final class UserProjectionHandler
{
    public function __construct(private LoggerInterface $logger) {}

    public function __invoke(UserCreatedEvent $event): void
    {
        $this->logger->info('UserProjectionHandler: updating read mode', [
            'userId' => (string) $event->id,
            'email'  => $event->email,
        ]);

        // In real code: $this->readRepository->upsert(...)
    }
}
