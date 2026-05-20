<?php

declare(strict_types=1);

namespace App\User\Domain\Error;

use Vortos\Domain\Error\DomainError;
use Vortos\Domain\Error\HttpStatus;

#[HttpStatus(409)]
final class UserAlreadyExistsError extends DomainError
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
