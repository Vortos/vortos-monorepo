<?php

declare(strict_types=1);

namespace Vortos\Cqrs\Attribute;

use Attribute;

/**
 * Marks a class as a query handler.
 *
 * ## Minimal usage:
 *
 *   #[AsQueryHandler]
 *   final class GetUserQueryHandler
 *   {
 *       public function __construct(private UserReadRepository $repository) {}
 *
 *       public function __invoke(GetUserQuery $query): ?array
 *       {
 *           return $this->repository->findById($query->userId);
 *       }
 *   }
 *
 * Query class is inferred from __invoke() first parameter type.
 *
 * ## Contract
 *
 * Query handlers MUST NOT modify state.
 * Query handlers MUST NOT call UnitOfWork.
 * Query handlers MUST NOT dispatch events.
 * Return whatever the caller needs — array, ViewModel, PageResult, scalar, null.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class AsQueryHandler
{
}
