<?php

declare(strict_types=1);

namespace App\User\Application\Command\RegisterUser;

use Vortos\Domain\Command\AbstractCommand;
use Vortos\Domain\Command\AsIdempotencyKey;

final readonly class RegisterUserCommand extends AbstractCommand
{
    public function __construct(
        public readonly string $email,
        public readonly string $name,

        #[AsIdempotencyKey] 
        public readonly string $userId,
    ) {}
}
