<?php

declare(strict_types=1);

namespace App\User\Application\Command\RegisterUser;

use App\User\Domain\Entity\User;
use App\User\Domain\Entity\UserId;
use App\User\Domain\Exception\UserAlreadyExistException;
use App\User\Infrastructure\Repository\UserRepository;
use Vortos\Auth\Contract\PasswordHasherInterface;
use Vortos\Cqrs\Attribute\AsCommandHandler;

#[AsCommandHandler]
final class RegisterUserCommandHandler
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly PasswordHasherInterface $hasher,
    ) {}

    public function __invoke(RegisterUserCommand $command): User
    {
        if ($this->userRepository->findByEmail($command->email) !== null) {
            throw UserAlreadyExistException::withEmail($command->email);
        }

        $user = User::registerUser(
            id: UserId::fromString($command->userId),
            name: $command->name,
            email: $command->email,
            passwordHash: $this->hasher->hash($command->password),
        );

        $this->userRepository->save($user);

        return $user;
    }
}
