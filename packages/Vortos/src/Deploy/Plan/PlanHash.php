<?php

declare(strict_types=1);

namespace Vortos\Deploy\Plan;

final readonly class PlanHash
{
    public string $value;

    private function __construct(string $value)
    {
        $this->value = $value;
    }

    public static function fromPlanJson(string $canonicalJson): self
    {
        return new self('sha256:' . hash('sha256', $canonicalJson));
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->value, $other->value);
    }

    public function toString(): string
    {
        return $this->value;
    }
}
