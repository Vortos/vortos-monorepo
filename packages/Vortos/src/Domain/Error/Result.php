<?php

declare(strict_types=1);

namespace Vortos\Domain\Error;

/**
 * @template T
 */
final class Result
{
    private function __construct(
        private readonly bool $ok,
        private readonly mixed $value,
        private readonly ?DomainError $domainError,
    ) {}

    /** @param T $value */
    public static function ok(mixed $value): self
    {
        return new self(true, $value, null);
    }

    public static function fail(DomainError $error): self
    {
        return new self(false, null, $error);
    }

    public function isOk(): bool
    {
        return $this->ok;
    }

    public function isFailure(): bool
    {
        return !$this->ok;
    }

    /**
     * Returns the value or throws the DomainError if this is a failure.
     *
     * @return T
     */
    public function unwrap(): mixed
    {
        if (!$this->ok) {
            throw $this->domainError;
        }

        return $this->value;
    }

    /**
     * Returns the DomainError or throws if this is a success.
     */
    public function error(): DomainError
    {
        if ($this->ok) {
            throw new \LogicException('Cannot get error from a successful Result.');
        }

        return $this->domainError;
    }

    /**
     * Transforms the value if ok, passes failure through unchanged.
     *
     * @template U
     * @param callable(T): U $fn
     * @return self<U>
     */
    public function map(callable $fn): self
    {
        if (!$this->ok) {
            return $this;
        }

        return self::ok($fn($this->value));
    }
}
