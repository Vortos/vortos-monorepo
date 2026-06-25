<?php

declare(strict_types=1);

namespace Vortos\Backup\Drill;

use DateTimeImmutable;
use Vortos\Backup\Domain\DatabaseEngine;

final readonly class DrillReport
{
    /**
     * @param list<InvariantResult> $invariants
     */
    public function __construct(
        public string $id,
        public DatabaseEngine $engine,
        public string $environment,
        public string $artifactId,
        public DateTimeImmutable $startedAt,
        public int $rtoMs,
        public string $outcome,
        public array $invariants,
        public ?string $error = null,
    ) {}

    public function passed(): bool
    {
        return $this->outcome === 'passed';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'engine' => $this->engine->value,
            'environment' => $this->environment,
            'artifact_id' => $this->artifactId,
            'started_at' => $this->startedAt->format(DATE_ATOM),
            'rto_ms' => $this->rtoMs,
            'outcome' => $this->outcome,
            'invariants' => array_map(
                static fn (InvariantResult $r): array => $r->toArray(),
                $this->invariants,
            ),
            'error' => $this->error,
        ];
    }
}
