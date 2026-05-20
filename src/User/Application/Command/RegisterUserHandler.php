<?php

declare(strict_types=1);

namespace App\User\Application\Command;

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
        $user = User::register(
            name:          $command->name,
            email:         $command->email,
            plainPassword: $command->password,
        );

        $this->repository->save($user);

        return $user;
    }
}
