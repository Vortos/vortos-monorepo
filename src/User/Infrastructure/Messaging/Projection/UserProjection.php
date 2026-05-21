<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Messaging\Projection;

use App\User\Domain\Event\UserRegistered;
use App\User\Infrastructure\Persistence\Mongo\UserReadRepository;
use Vortos\Cqrs\Attribute\AsProjectionHandler;
use Vortos\Cqrs\Projection\ProjectionHandlerInterface;
use Vortos\Domain\Event\EventEnvelope;

#[AsProjectionHandler(handlerId: 'user.registered.project', consumer: 'user.events')]
final class UserProjection implements ProjectionHandlerInterface
{
    public function __construct(
        private readonly UserReadRepository $readRepository,
    ) {}

    public function __invoke(UserRegistered $event, EventEnvelope $envelope): void
    {
        $this->readRepository->upsert($envelope->aggregateId, [
            '_id'       => $envelope->aggregateId,
            'name'      => $event->name,
            'email'     => $event->email,
            'status'    => 'active',
            'createdAt' => $envelope->occurredAt->format(\DateTimeInterface::ATOM),
        ]);
    }
}
