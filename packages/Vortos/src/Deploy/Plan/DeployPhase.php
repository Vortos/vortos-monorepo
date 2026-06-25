<?php

declare(strict_types=1);

namespace Vortos\Deploy\Plan;

final readonly class DeployPhase
{
    /** @param list<DeployStep> $steps */
    public function __construct(
        public PhaseKind $kind,
        public array $steps,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'steps' => array_map(
                static fn (DeployStep $s): array => $s->toArray(),
                $this->steps,
            ),
        ];
    }
}
