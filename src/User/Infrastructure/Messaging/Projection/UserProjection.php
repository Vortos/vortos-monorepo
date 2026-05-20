<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Messaging\Projection;

use App\User\Domain\Event\UserRegisteredEvent;
use App\User\Infrastructure\Database\UserReadRepository;
use Vortos\Cqrs\Attribute\AsProjectionHandler;
use Vortos\Cqrs\Projection\ProjectionHandlerInterface;

#[AsProjectionHandler(handlerId: 'user.registered.project', consumer: 'user.events')]
final class UserProjection implements ProjectionHandlerInterface
{
    public function __construct(
        private readonly UserReadRepository $readRepository,
    ) {}

    public function __invoke(UserRegisteredEvent $event): void
    {
        $this->readRepository->upsert($event->aggregateId(), [
            '_id'       => $event->aggregateId(),
            'name'      => $event->name,
            'email'     => $event->email,
            'status'    => 'active',
            'createdAt' => $event->occurredAt()->format(\DateTimeInterface::ATOM),
        ]);
    }
}
