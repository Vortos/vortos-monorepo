<?php

declare(strict_types=1);

namespace Vortos\Domain\Error;

abstract class DomainError extends \RuntimeException
{
    public function __construct(string $message, array $context = [], ?\Throwable $previous = null)
    {
        $this->errorContext = $context;
        parent::__construct($message, 0, $previous);
    }

    private array $errorContext;

    public function errorCode(): string
    {
        $shortName = substr(strrchr(static::class, '\\') ?: static::class, 1);
        $name = preg_replace('/Error$/', '', $shortName) ?? $shortName;
        return strtoupper((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
    }

    public function context(): array
    {
        return $this->errorContext;
    }
}
