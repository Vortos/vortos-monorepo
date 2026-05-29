<?php

declare(strict_types=1);

namespace App\User\Application\Query;

use App\User\Application\UserReadRepositoryInterface;
use App\User\Domain\Error\UserNotFoundError;
use Vortos\Cqrs\Attribute\AsQueryHandler;
use Vortos\Domain\Error\Result;

#[AsQueryHandler]
final class GetUserQueryHandler
{
    public function __construct(
        private readonly UserReadRepositoryInterface $repository,
    ) {}

    public function __invoke(GetUserQuery $query): Result
    {
        $user = $this->repository->findById($query->userId);

        if ($user === null) {
            return Result::fail(new UserNotFoundError(
                "User '{$query->userId}' not found.",
                context: ['userId' => $query->userId],
            ));
        }

        return Result::ok($user);
    }
}
