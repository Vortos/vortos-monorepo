<?php

declare(strict_types=1);

namespace Vortos\Backup\Pitr;

final readonly class CheckpointerWakeResult
{
    public function __construct(
        public CheckpointerWakeOutcome $outcome,
        /** Seconds the oldest unarchived WAL has waited, when measured. */
        public ?int $exposureSeconds,
        /** Exception class only — a driver message can carry a DSN. */
        public ?string $errorClass,
    ) {}

    public function summary(): string
    {
        return match ($this->outcome) {
            CheckpointerWakeOutcome::Woken => sprintf('archive_timeout stranded after restart (%ds unarchived, no checkpoint since start): CHECKPOINT issued to wake the checkpointer', $this->exposureSeconds ?? 0),
            CheckpointerWakeOutcome::WakeFailed => sprintf('archive_timeout stranded after restart (%ds unarchived) but CHECKPOINT was refused (%s); grant pg_checkpoint', $this->exposureSeconds ?? 0, $this->errorClass ?? '?'),
            CheckpointerWakeOutcome::ArchivingFailing => sprintf('%ds of WAL unarchived and archive_command is failing; not forcing switches', $this->exposureSeconds ?? 0),
            CheckpointerWakeOutcome::Indeterminate => sprintf('checkpointer timers unreadable (%s)', $this->errorClass ?? '?'),
            default => $this->outcome->value,
        };
    }
}
