<?php

declare(strict_types=1);

namespace App\User\Application\ReadModel;

final readonly class UserReadModel
{
    public function __construct(
        public string $id,
        public string $email,
        public string $name,
        public string $status,
        public ?string $createdAt,
    ) {}
}
