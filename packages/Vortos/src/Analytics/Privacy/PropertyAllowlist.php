<?php

declare(strict_types=1);

namespace Vortos\Analytics\Privacy;

/**
 * Only allowlisted property/trait keys survive — redact-by-construction (mirrors
 * `Secrets\SecretValue`). Unknown keys are dropped *before* any serialization, so
 * they never exist in an outbound payload. An empty allowlist drops everything
 * (opt-in widening only, never opt-out).
 */
final readonly class PropertyAllowlist
{
    /** @param list<string> $allowedKeys */
    public function __construct(private array $allowedKeys = []) {}

    /**
     * @param array<string,mixed> $properties
     * @return array<string,mixed>
     */
    public function filter(array $properties): array
    {
        if ($this->allowedKeys === []) {
            return [];
        }

        $allowed = array_flip($this->allowedKeys);

        return array_intersect_key($properties, $allowed);
    }

    /** @return list<string> */
    public function allowedKeys(): array
    {
        return $this->allowedKeys;
    }
}
