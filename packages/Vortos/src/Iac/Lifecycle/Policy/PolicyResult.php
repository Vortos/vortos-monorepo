<?php

declare(strict_types=1);

namespace Vortos\Iac\Lifecycle\Policy;

final readonly class PolicyResult
{
    /** @param list<PolicyViolation> $violations */
    public function __construct(
        public array $violations,
    ) {}

    public function passed(): bool
    {
        return $this->violations === [];
    }

    public static function pass(): self
    {
        return new self([]);
    }

    /** @param list<PolicyViolation> $violations */
    public static function fail(array $violations): self
    {
        return new self($violations);
    }
}
