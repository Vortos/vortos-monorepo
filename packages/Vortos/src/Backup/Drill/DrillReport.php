<?php

declare(strict_types=1);

namespace Vortos\Backup\Drill;

use DateTimeImmutable;
use Vortos\Backup\Domain\BackupKind;
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
        public DrillOutcome $outcome,
        public array $invariants,
        public ?string $error = null,
        /**
         * Which restore path this drill proved.
         *
         * Appended rather than placed next to $engine so existing call sites keep working, and
         * nullable because reports written before point-in-time drilling existed cannot say. It is
         * recorded at all because "the last drill passed" stopped being one fact the moment there
         * were two drills: a green logical restore says nothing about whether the WAL chain is
         * recoverable, and a dashboard that blurs them is how a broken PITR pipeline hides behind a
         * healthy daily dump.
         */
        public ?BackupKind $kind = null,
    ) {}

    /**
     * No `passed()`: it used to mean "restored", and that silently counted a drill over its recovery
     * objective as a success. Callers now say which question they are asking.
     */
    public function restored(): bool
    {
        return $this->outcome->restored();
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
            'kind' => $this->kind?->value,
            'outcome' => $this->outcome->value,
            'invariants' => array_map(
                static fn (InvariantResult $r): array => $r->toArray(),
                $this->invariants,
            ),
            'error' => $this->error,
        ];
    }
}
