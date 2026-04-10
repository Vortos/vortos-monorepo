<?php

declare(strict_types=1);

namespace App\User\Representation\Controller;

use App\User\Domain\Entity\UserId;
use App\User\Domain\Event\UserCreatedEvent;
use Vortos\Attribute\ApiController;
use Vortos\Messaging\Contract\EventBusInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\UuidV7;

#[ApiController]
#[Route('/test/publish', methods: ['GET'])]
final class TestPublishController
{
    public function __construct(
        private EventBusInterface $eventBus
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $this->eventBus->dispatch(new UserCreatedEvent(
            id: UserId::generate()->toString(),
            name: 'test-123',
            email: 'test@example.com'
        ));

        return new JsonResponse(['status' => 'dispatched']);
    }
}