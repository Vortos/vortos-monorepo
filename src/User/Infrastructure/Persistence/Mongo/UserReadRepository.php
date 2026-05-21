<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Persistence\Mongo;

use App\User\Application\ReadModel\UserReadModel;
use Vortos\PersistenceMongo\Read\MongoReadRepository;
use Vortos\PersistenceMongo\Schema\Attribute\MongoCollection;
use Vortos\PersistenceMongo\Schema\Attribute\MongoIndex;

/**
 * @extends MongoReadRepository<UserReadModel>
 */
#[MongoCollection('users')]
#[MongoIndex(key: ['email' => 1], unique: true)]
#[MongoIndex(key: ['createdAt' => -1, '_id' => -1])]
final class UserReadRepository extends MongoReadRepository
{
    protected function fromDocument(array $doc): UserReadModel
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
