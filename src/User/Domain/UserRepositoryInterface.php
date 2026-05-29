<?php

declare(strict_types=1);

namespace App\User\Domain;

interface UserRepositoryInterface
{
    public function findByEmail(Email $email): ?User;

    public function save(User $user): void;
}
