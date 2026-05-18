<?php

declare(strict_types=1);

namespace Vortos\PersistenceOrm\Exception;

/**
 * Base class for all Vortos ORM persistence exceptions.
 *
 * Application code catches this (or its subclasses) rather than importing
 * Doctrine or DBAL exceptions directly. This keeps the domain and application
 * layers free of infrastructure dependencies.
 */
class PersistenceException extends \RuntimeException
{
    public static function wrap(\Throwable $cause, string $message = ''): static
    {
        return new static($message ?: $cause->getMessage(), (int) $cause->getCode(), $cause);
    }
}
