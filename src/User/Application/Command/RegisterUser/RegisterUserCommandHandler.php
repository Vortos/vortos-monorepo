<?php

declare(strict_types=1);

namespace App\User\Application\Command\RegisterUser;

use App\User\Domain\Entity\User;
use Psr\Log\LoggerInterface;
use Vortos\Cqrs\Attribute\AsCommandHandler;

#[AsCommandHandler()]
final class RegisterUserCommandHandler 
{
    public function __construct(private LoggerInterface $logger)
    {}

    public function __invoke(RegisterUserCommand $command): User
    {
        $user = User::registerUser(
            $command->name,
            $command->email,
            true
        );

        $this->logger->alert("command handler");

        return $user;
    }
}
