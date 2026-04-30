<?php

declare(strict_types=1);

namespace App\User\Application\Command\RegisterUser;

use Symfony\Component\Validator\Constraints as Assert;
use Vortos\Domain\Command\AbstractCommand;
use Vortos\Domain\Command\AsIdempotencyKey;

final readonly class RegisterUserCommand extends AbstractCommand
{
    public function __construct(
        #[AsIdempotencyKey]
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public string $userId,

        #[Assert\NotBlank]
        #[Assert\Email]
        #[Assert\Length(max: 255)]
        public string $email,

        #[Assert\NotBlank]
        #[Assert\Length(min: 2, max: 100)]
        public string $name,

        #[Assert\NotBlank]
        #[Assert\Length(min: 8)]
        public string $password,
    ) {}
}
