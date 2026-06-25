<?php

declare(strict_types=1);

namespace Vortos\Deploy\State;

final readonly class ContractSoakRecord
{
    public function __construct(
        public string $migrationId,
        public \DateTimeImmutable $firstObservedAt,
        public int $observedAtGeneration,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'migration_id' => $this->migrationId,
            'first_observed_at' => $this->firstObservedAt->format(\DateTimeInterface::ATOM),
            'observed_at_generation' => $this->observedAtGeneration,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            migrationId: (string) $data['migration_id'],
            firstObservedAt: new \DateTimeImmutable((string) $data['first_observed_at']),
            observedAtGeneration: (int) $data['observed_at_generation'],
        );
    }
}
