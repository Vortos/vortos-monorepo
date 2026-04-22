<?php

declare(strict_types=1);

namespace App\User\Representation\Controller;

use App\User\Application\Command\RegisterUser\RegisterUserCommand;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\UuidV7;
use Vortos\Attribute\ApiController;
use Vortos\Cqrs\Command\CommandBusInterface;

#[ApiController]
#[Route('/test/command', methods: ['GET'])]
final class TestCommandController
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private CacheInterface $cache
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $this->commandBus->dispatch(new RegisterUserCommand(
            email: 'alice@example.com',
            name: 'Alice',
            userId: (string) new UuidV7(),
        ));

        $this->cache->set('test_key', 'hello_vortos43432', 60);
        $value = $this->cache->get('test_key');
        // return JsonResponse(['value' => $value])Undefined method 'invalidateTags'.intelephense(P1013)
        

        return new JsonResponse(['status' => 'command dispatched : ' . json_encode($value)]);
    }
}
