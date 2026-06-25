<?php

declare(strict_types=1);

namespace Vortos\Secrets\Preflight;

use Vortos\Secrets\Value\SecretKey;

/**
 * The result of diffing {@see RequiredSecrets} against what a provider actually
 * has. Fail-closed by construction: {@see isSatisfied()} is true only when nothing
 * is missing, and {@see explain()} always NAMES every gap — never a bare "a secret
 * is missing".
 */
final readonly class PreflightReport
{
    /**
     * @param list<SecretKey> $present
     * @param list<SecretKey> $missing
     */
    public function __construct(
        public array $present,
        public array $missing,
    ) {}

    public function isSatisfied(): bool
    {
        return $this->missing === [];
    }

    /** @return list<string> */
    public function missingKeyNames(): array
    {
        return array_map(static fn (SecretKey $k): string => $k->value(), $this->missing);
    }

    public function explain(): string
    {
        if ($this->isSatisfied()) {
            return 'All required secrets are present.';
        }

        return sprintf(
            'Missing %d required secret(s): %s',
            count($this->missing),
            implode(', ', $this->missingKeyNames()),
        );
    }

    /** @return array{satisfied: bool, present: list<string>, missing: list<string>} */
    public function toArray(): array
    {
        return [
            'satisfied' => $this->isSatisfied(),
            'present' => array_map(static fn (SecretKey $k): string => $k->value(), $this->present),
            'missing' => $this->missingKeyNames(),
        ];
    }
}
