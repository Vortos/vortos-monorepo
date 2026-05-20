<?php

declare(strict_types=1);

namespace App\User\Application\Command;

use App\User\Domain\Email;
use App\User\Domain\Error\UserAlreadyExistsError;
use App\User\Domain\User;
use App\User\Infrastructure\Database\UserWriteRepository;
use Vortos\Cqrs\Attribute\AsCommandHandler;

#[AsCommandHandler]
final class RegisterUserHandler
{
    public function __construct(
        private readonly UserWriteRepository $repository,
    ) {}

    public function __invoke(RegisterUserCommand $command): User
    {
        if ($this->repository->findByEmail(new Email($command->email)) !== null) {
            throw new UserAlreadyExistsError(
                "Email '{$command->email}' is already registered.",
                context: ['email' => $command->email],
            );
        }

        $user = User::register(
            name:          $command->name,
            email:         $command->email,
            plainPassword: $command->password,
        );

        $this->repository->save($user);

        return $user;
    }
}
