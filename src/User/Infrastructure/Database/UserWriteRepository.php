<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Database;

use App\User\Domain\Email;
use App\User\Domain\User;
use App\User\Domain\UserId;
use App\User\Domain\UserRepositoryInterface;
use Vortos\PersistenceDbal\Attribute\UsesDbalMapper;
use Vortos\PersistenceDbal\Write\DbalStore;

#[UsesDbalMapper(UserMapper::class)]
final class UserWriteRepository implements UserRepositoryInterface
{
    public function __construct(private readonly DbalStore $store) {}

    public function save(User $user): void
    {
        $this->store->save($user);
    }

    public function delete(User $user): void
    {
        $this->store->delete($user);
    }

    public function findById(UserId $id): ?User
    {
        /** @var User|null */
        return $this->store->find($id);
    }

    public function findByEmail(Email $email): ?User
    {
        $row = $this->store->createQueryBuilder()
            ->select('*')
            ->from($this->store->mapper()->tableName())
            ->where('email = :email')
            ->setParameter('email', (string) $email)
            ->executeQuery()
            ->fetchAssociative();

        /** @var User|null */
        return $row !== false ? $this->store->mapper()->fromRow($row) : null;
    }
}
