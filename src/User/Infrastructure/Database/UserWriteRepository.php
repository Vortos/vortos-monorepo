<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Database;

use App\User\Domain\Email;
use App\User\Domain\User;
use App\User\Domain\UserId;
use App\User\Domain\UserRepositoryInterface;
use Doctrine\DBAL\Types\Types;
use Vortos\Domain\Aggregate\AggregateRoot;
use Vortos\PersistenceDbal\Write\DbalWriteRepository;

final class UserWriteRepository extends DbalWriteRepository implements UserRepositoryInterface
{
    protected function tableName(): string
    {
        return 'users';
    }

    protected function columnMap(): array
    {
        return [
            'id'            => Types::STRING,
            'name'          => Types::STRING,
            'email'         => Types::STRING,
            'password_hash' => Types::STRING,
            'version'       => Types::INTEGER,
        ];
    }

    protected function toRow(AggregateRoot $aggregate): array
    {
        /** @var User $aggregate */
        return [
            'id'            => (string) $aggregate->getId(),
            'name'          => $aggregate->getName(),
            'email'         => (string) $aggregate->getEmail(),
            'password_hash' => $aggregate->getPasswordHash(),
            'version'       => $aggregate->getVersion(),
        ];
    }

    protected function fromRow(array $row): AggregateRoot
    {
        return User::reconstruct(
            id:           UserId::fromString($row['id']),
            email:        new Email($row['email']),
            name:         $row['name'],
            passwordHash: $row['password_hash'],
            version:      (int) $row['version'],
        );
    }

    public function save(User $user): void
    {
        parent::save($user);
    }

    public function findByEmail(Email $email): ?User
    {
        $row = $this->connection()->createQueryBuilder()
            ->select('*')
            ->from($this->tableName())
            ->where('email = :email')
            ->setParameter('email', (string) $email)
            ->executeQuery()
            ->fetchAssociative();

        return $row !== false ? $this->fromRow($row) : null;
    }
}
