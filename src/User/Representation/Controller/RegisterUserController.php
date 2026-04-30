<?php

declare(strict_types=1);

namespace App\User\Representation\Controller;

use App\User\Application\Command\RegisterUser\RegisterUserCommand;
use App\User\Domain\Exception\UserAlreadyExistException;
use App\User\Representation\Request\RegisterUserRequest;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\UuidV7;
use Vortos\Cqrs\Command\CommandBusInterface;
use Vortos\Http\Attribute\ApiController;

#[ApiController]
#[Route('/api/auth/register', methods: ['POST'])]
final class RegisterUserController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {}

    public function __invoke(RegisterUserRequest $request): JsonResponse
    {
        try {
            $this->commandBus->dispatch(new RegisterUserCommand(
                userId:   (string) new UuidV7(),
                email:    $request->email,
                name:     $request->name,
                password: $request->password,
            ));
        } catch (UserAlreadyExistException) {
            return new JsonResponse([
                'error'      => 'validation_failed',
                'message'    => 'The given data was invalid.',
                'violations' => ['email' => ['This email is already registered.']],
            ], 422);
        }

        return new JsonResponse(['message' => 'User registered successfully.'], 201);
    }
}
