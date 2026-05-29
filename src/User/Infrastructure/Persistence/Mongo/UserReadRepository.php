<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Persistence\Mongo;

use App\User\Application\ReadModel\UserReadModel;
use App\User\Application\UserReadRepositoryInterface;
use Vortos\PersistenceMongo\Read\MongoStore;
use Vortos\PersistenceMongo\Schema\Attribute\MongoCollection;
use Vortos\PersistenceMongo\Schema\Attribute\MongoIndex;

#[MongoCollection('users')]
#[MongoIndex(key: ['email' => 1], unique: true)]
#[MongoIndex(key: ['createdAt' => -1, '_id' => -1])]
final class UserReadRepository implements UserReadRepositoryInterface
{
    public function __construct(private readonly MongoStore $store) {}

    public function findById(string $id): ?UserReadModel
    {
        $doc = $this->store->findById($id);

        return $doc !== null ? $this->fromDocument($doc) : null;
    }

    private function fromDocument(array $doc): UserReadModel
    {
        return new UserReadModel(
            id:        $doc['_id'],
            email:     $doc['email'] ?? '',
            name:      $doc['name'] ?? '',
            status:    $doc['status'] ?? 'active',
            createdAt: $doc['createdAt'] ?? null,
        );
    }
}
