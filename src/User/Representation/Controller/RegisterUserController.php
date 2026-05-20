<?php

declare(strict_types=1);

namespace App\User\Representation\Controller;

use App\User\Application\Command\RegisterUserCommand;
use App\User\Domain\User;
use App\User\Representation\Request\RegisterUserRequest;
use Vortos\Http\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Vortos\Cqrs\Command\CommandBusInterface;
use Vortos\Cqrs\Validation\VortosValidator;
use Vortos\Http\Attribute\AsController;
use Vortos\Http\Request;
use Vortos\Security\Csrf\Attribute\SkipCsrf;

#[AsController]
#[Route('/api/users/register', name: 'users.register', methods: ['POST'])]
#[SkipCsrf]
final class RegisterUserController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
        private readonly VortosValidator $validator,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $dto = RegisterUserRequest::fromRequest($request, $this->validator);

        /** @var User $user */
        $user = $this->commandBus->dispatch(new RegisterUserCommand(
            name:  $dto->name,
            email: $dto->email,
            password: $dto->password,
            idempotencyKey: $request->headers->get('Idempotency-Key'),
        ));

        return new JsonResponse([
            'id'    => (string) $user->getId(),
            'name'  => $user->getName(),
            'email' => (string) $user->getEmail(),
        ], 201);
    }
}
