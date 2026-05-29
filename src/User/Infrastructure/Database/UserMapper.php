<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Database;

use Doctrine\DBAL\Types\Types;
use Vortos\Domain\Aggregate\AggregateRoot;
use Vortos\PersistenceDbal\Write\DbalMapper;
use App\User\Domain\Email;
use App\User\Domain\User;
use App\User\Domain\UserId;

final class UserMapper implements DbalMapper
{
    public function tableName(): string
    {
        return 'users';
    }

    public function columnMap(): array
    {
        return [
            'id'            => Types::STRING,
            'name'          => Types::STRING,
            'email'         => Types::STRING,
            'password_hash' => Types::STRING,
            'version'       => Types::INTEGER,
        ];
    }

    public function toRow(AggregateRoot $aggregate): array
    {
        assert($aggregate instanceof User);

        return [
            'id'            => (string) $aggregate->getId(),
            'name'          => $aggregate->getName(),
            'email'         => (string) $aggregate->getEmail(),
            'password_hash' => $aggregate->getPasswordHash(),
            'version'       => $aggregate->getVersion(),
        ];
    }

    public function fromRow(array $row): AggregateRoot
    {
        return User::reconstruct(
            id:           UserId::fromString($row['id']),
            email:        new Email($row['email']),
            name:         $row['name'],
            passwordHash: $row['password_hash'],
            version:      (int) $row['version'],
        );
    }
}
