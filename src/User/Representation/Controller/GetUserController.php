<?php

declare(strict_types=1);

namespace App\User\Representation\Controller;

use App\User\Application\Query\GetUserQuery;
use Vortos\Http\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Vortos\Auth\Attribute\RequiresAuth;
use Vortos\Authorization\Attribute\RequiresPermission;
use Vortos\Cqrs\Query\QueryBusInterface;
use Vortos\Http\Attribute\AsController;

#[AsController]
#[Route('/api/users/{id}', name: 'users.get', methods: ['GET'])]
#[RequiresAuth]
#[RequiresPermission('users.read.any')]
final class GetUserController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
    ) {}

    public function __invoke(string $id): JsonResponse
    {
        /** @var array $user */
        $user = $this->queryBus->ask(new GetUserQuery(userId: $id))->unwrap();

        return new JsonResponse($user);
    }
}
