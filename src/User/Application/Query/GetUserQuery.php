<?php

declare(strict_types=1);

namespace App\User\Application\Query;

use Vortos\Domain\Query\QueryInterface;

final readonly class GetUserQuery implements QueryInterface
{
    public function __construct(
        public readonly string $userId,
    ) {}
}
