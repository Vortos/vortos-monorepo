<?php

declare(strict_types=1);

namespace Vortos\Deploy\Gate;

final readonly class SmokeResult
{
    /** @param list<SmokeCheckResult> $checks */
    public function __construct(
        public bool $passed,
        public array $checks = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'passed' => $this->passed,
            'checks' => array_map(
                static fn (SmokeCheckResult $c): array => $c->toArray(),
                $this->checks,
            ),
        ];
    }
}
