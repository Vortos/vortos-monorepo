<?php

declare(strict_types=1);

namespace App\User\Representation\Controller;

use App\User\Application\Query\GetUser\GetUserQuery;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\UuidV7;
use Vortos\Attribute\ApiController;
use Vortos\Cqrs\Query\QueryBusInterface;

#[ApiController]
#[Route('/test/query', methods: ['GET'])]
final class TestQueryController
{
    public function __construct(private QueryBusInterface $queryBus) {}

    public function __invoke(Request $request): JsonResponse
    {
        $result = $this->queryBus->ask(new GetUserQuery(userId: 'test-id'));
        return new JsonResponse($result);
    }
}
