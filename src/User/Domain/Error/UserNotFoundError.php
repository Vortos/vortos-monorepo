<?php

declare(strict_types=1);

namespace App\User\Domain\Error;

use Vortos\Domain\Error\DomainError;
use Vortos\Domain\Error\HttpStatus;

#[HttpStatus(404)]
final class UserNotFoundError extends DomainError
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
