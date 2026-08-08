<?php

declare(strict_types=1);

namespace Vortos\Backup\Runtime;

/**
 * The result of one backup-lifecycle occurrence.
 *
 * WHY THIS IS NOT JUST A STRING
 * -----------------------------
 * It used to be. `execute()` returned a human summary, and the worker treated every return as a
 * completed occurrence — advancing the schedule's watermark and re-basing its cron.
 *
 * That silently destroyed one distinction that matters. A backup run whose single-flight lock is
 * already held does no work at all: `BackupRunner::run()` returns null, which the lifecycle runner
 * rendered as the perfectly cheerful "backup produced no artifact" and the worker recorded as
 * success. The occurrence was consumed. For a six-hourly schedule that is six hours of RPO given
 * away, and it is not rare — the base-backup program and the worker's logical dump share one lock
 * scope and both start with the container, so a cold start loses the race reliably.
 *
 * Production lost four days of logical backups partly this way: every collision reported success,
 * so nothing failed, nothing retried, and no alarm had anything to fire on.
 *
 * Three outcomes are needed, not two, and "did nothing" must be representable — a skip is neither a
 * success to be recorded nor a failure to be backed off and alerted on. It is simply not-yet-done,
 * and the only correct response is to leave the watermark alone and try again shortly.
 */
final readonly class LifecycleOutcome
{
    private function __construct(
        public LifecycleOutcomeStatus $status,
        public string $summary,
    ) {}

    /** The occurrence ran to completion. The watermark may advance. */
    public static function completed(string $summary): self
    {
        return new self(LifecycleOutcomeStatus::Completed, $summary);
    }

    /**
     * The occurrence did not run — something else holds the single-flight lock for this scope.
     *
     * The watermark must NOT advance: nothing happened, so the occurrence is still owed.
     */
    public static function skipped(string $summary): self
    {
        return new self(LifecycleOutcomeStatus::Skipped, $summary);
    }

    public function isSkipped(): bool
    {
        return $this->status === LifecycleOutcomeStatus::Skipped;
    }

    public function __toString(): string
    {
        return $this->summary;
    }
}
