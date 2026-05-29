<?php

declare(strict_types=1);

namespace App\User\Application;

use App\User\Application\ReadModel\UserReadModel;

interface UserReadRepositoryInterface
{
    public function findById(string $id): ?UserReadModel;
}
