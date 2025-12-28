<?php

namespace App\User\Infrastructure\Repository;

use App\User\Domain\Entity\User;
use App\User\Domain\Exception\UserNotFoundException;
use App\User\Domain\Repository\UserRepositoryInterface;
use Fortizan\Tekton\Persistence\PersistenceManager;

class DoctrineUserRepository implements UserRepositoryInterface
{
    public function __construct(
        private PersistenceManager $persistence
    ) {}

    public function save(User $user): void
    {
        $this->persistence->sourceWriter()->persist($user);
        $this->persistence->sourceWriter()->flush();
    }

    public function getById(string $id): User
    {
        $user = $this->persistence->sourceWriter()->native()->find(User::class, $id);

        if ($user === null) {
            throw new UserNotFoundException("No user for the id {$id}");
        }

        return $user;
    }
}
