<?php

declare(strict_types=1);

namespace App\User\Application\Query;

use App\User\Infrastructure\Database\UserReadRepository;
use Vortos\Cqrs\Attribute\AsQueryHandler;

#[AsQueryHandler]
final class GetUserQueryHandler
{
    public function __construct(
        private readonly UserReadRepository $repository,
    ) {}

    public function __invoke(GetUserQuery $query): ?array
    {
        return $this->repository->findById($query->userId);
    }
}
