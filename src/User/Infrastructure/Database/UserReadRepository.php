<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Database;

use Vortos\PersistenceMongo\Read\MongoReadRepository;

final class UserReadRepository extends MongoReadRepository
{
    protected function collectionName(): string
    {
        return 'users';
    }

    protected function fromDocument(array $doc): array
    {
        return [
            'id'        => $doc['_id'],
            'name'      => $doc['name'] ?? '',
            'email'     => $doc['email'] ?? '',
            'status'    => $doc['status'] ?? 'active',
            'createdAt' => $doc['createdAt'] ?? null,
        ];
    }

    protected function indexes(): array
    {
        return [
            ['key' => ['email' => 1], 'options' => ['unique' => true]],
            ['key' => ['createdAt' => -1, '_id' => -1], 'options' => []],
        ];
    }
}
