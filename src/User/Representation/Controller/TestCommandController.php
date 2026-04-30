<?php

declare(strict_types=1);

namespace App\User\Representation\Controller;

use App\User\Application\Command\RegisterUser\RegisterUserCommand;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\UuidV7;
use Vortos\Auth\Attribute\RequiresAuth;
use Vortos\Cache\Contract\TaggedCacheInterface;
use Vortos\Cqrs\Command\CommandBusInterface;
use Vortos\Http\Attribute\ApiController;

#[ApiController]
#[Route('/test/command', methods: ['GET'])]
// #[RequiresAuth]
final class TestCommandController
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private TaggedCacheInterface $cache
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $this->commandBus->dispatch(new RegisterUserCommand(
            email: 'alice@example.com',
            name: 'Alice',
            userId: (string) new UuidV7(),
            password: ''
        ));

        $this->cache->set('test_key', 'hello_vortos', 60);
        $value = $this->cache->get('test_key');
        // return JsonResponse(['value' => $value])Undefined method 'invalidateTags'.intelephense(P1013)
        

        $this->cache->setWithTags('user:1:profile', ['name' => 'Alice'], ['user:1'], 3600);
        $this->cache->setWithTags('user:1:posts', ['count' => 5], ['user:1'], 3600);
        $this->cache->invalidateTags(['user:1']);
        // both keys should now return null
        $profile = $this->cache->get('user:1:profile'); // null
        $posts = $this->cache->get('user:1:posts');

        return new JsonResponse(['status' => 'command dispatche : ' . json_encode($profile)]);
    }
}
