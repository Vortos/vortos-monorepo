<?php

declare(strict_types=1);

namespace App\User\Application\Query\GetUser;

use Vortos\Cqrs\Attribute\AsQueryHandler;

#[AsQueryHandler()]
final class GetUserQueryHandler
{
    public function __invoke(GetUserQuery $query): ?array
    {
        // Stub — return fake data for now
        return [
            'id'    => $query->userId,
            'email' => 'alice@example.com',
            'name'  => 'Alice',
        ];
    }
}
