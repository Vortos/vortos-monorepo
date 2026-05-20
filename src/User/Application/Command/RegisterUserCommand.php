<?php

declare(strict_types=1);

namespace App\User\Application\Command;

use Vortos\Domain\Command\CommandInterface;

final readonly class RegisterUserCommand implements CommandInterface
{
    public function __construct(
        public readonly string $name,
        public readonly string $email,
        public readonly string $password,
        private readonly ?string $idempotencyKey = null,
    ) {}

    public function idempotencyKey(): ?string
    {
        return $this->idempotencyKey;
    }
}
