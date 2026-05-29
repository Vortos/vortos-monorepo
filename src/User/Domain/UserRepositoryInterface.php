<?php

declare(strict_types=1);

namespace App\User\Domain;

interface UserRepositoryInterface
{
    public function save(User $user): void;

    public function delete(User $user): void;

    public function findById(UserId $id): ?User;

    public function findByEmail(Email $email): ?User;
}
