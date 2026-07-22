<?php

declare(strict_types=1);

namespace Vortos\Backup\Runtime;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Vortos\Backup\Event\BackupEvent;
use Vortos\Backup\Event\BackupEventSinkInterface;
use Vortos\Backup\Schedule\BackupSchedule;

/**
 * R8-6 (A8): the framework-owned backup runtime. A single, long-running, in-container process that
 * fires the declared lifecycle (backup / retention / drill) on their crons — the containerized
 * replacement for host cron, driven entirely by config/backup.php.
 *
 * Robustness is built in, not bolted on:
 *  - **single-flight**: the worker is a sequential loop, so a schedule can never overlap itself, and
 *    a long backup cannot race a retention run;
 *  - **durable watermark**: {@see ScheduleStateStoreInterface} persists the last-fired time so a
 *    restart neither double-fires nor loses its place. Deploy note: the store must be backed by
 *    something that survives the container — a *lost* watermark is a cold start, and a cold start
 *    runs (see {@see isDue()}), so an ephemeral store degrades to "one extra run per restart";
 *  - **misfire = run-once-on-recovery**: after downtime — or with no watermark at all — a schedule
 *    fires once and re-bases, never a storm of missed occurrences and never silence;
 *  - **bounded retry with backoff**: a failed occurrence retries with exponential backoff up to a
 *    cap, then is given up until its next cron and surfaced as a backup.failed alert (dead-man).
 *
 * The scheduling decision lives in the pure {@see tick()} (fake clock + fake runner in tests); the
 * command wraps it in the sleep/SIGTERM loop.
 */
final class BackupWorker
{
    /** @var list<BackupSchedule> */
    private readonly array $schedules;

    /**
     * @param iterable<BackupSchedule> $schedules
     */
    public function __construct(
        iterable $schedules,
        private readonly BackupLifecycleRunnerInterface $runner,
        private readonly ScheduleStateStoreInterface $state,
        private readonly ClockInterface $clock,
        private readonly ?BackupEventSinkInterface $events = null,
        private readonly CronDueEvaluator $evaluator = new CronDueEvaluator(),
        private readonly int $maxRetries = 5,
        private readonly int $baseBackoffSeconds = 30,
    ) {
        $this->schedules = array_values([...$schedules]);
    }

    /**
     * Evaluate every schedule against $now and fire the due ones. Returns a per-schedule summary so
     * the caller (command) can log it.
     *
     * $now is injectable rather than always read from the clock so scheduling stays fully
     * deterministic under test; omit it in production and the worker's own clock is used.
     *
     * @return list<array{schedule: string, result: string}>
     */
    public function tick(?DateTimeImmutable $now = null): array
    {
        $now ??= $this->clock->now();
        $log = [];

        foreach ($this->schedules as $schedule) {
            $state = $this->state->get($schedule->name);

            // Honour the backoff window after a failure — don't hot-loop a broken backup.
            if ($state->retryAfter !== null && $now < $state->retryAfter) {
                continue;
            }

            if (!$this->isDue($schedule, $state, $now)) {
                continue;
            }

            $log[] = ['schedule' => $schedule->name, 'result' => $this->fire($schedule, $state, $now)];
        }

        return $log;
    }

    /**
     * COLD START IS DUE NOW — this is the whole of the "misfire = run-once-on-recovery" contract, and
     * getting it wrong is a silent, unbounded data-loss bug rather than a scheduling nicety.
     *
     * With no watermark we do not know when this schedule last ran, so the only safe answer is to run
     * it once now and establish one. The tempting alternative — derive a baseline from "now" and wait
     * for the next cron occurrence after it — is what this method exists to prevent: because `tick()`
     * re-reads the store every pass, that baseline slides forward on *every* tick, `nextDueAfter()` is
     * by construction always in the future, and the schedule never becomes due. Not "late": never.
     * The worker sits in its sleep loop looking perfectly healthy, emitting nothing, backing up
     * nothing, indefinitely.
     *
     * That is not hypothetical. It took production down for 15 days (2026-07-07 → 07-22): the state
     * file lived in a container layer that a recreate discarded, and the worker never fired again.
     * Because it never *attempted* a run it never failed, so the `backup.failed` dead-man never fired
     * either — see BackupFreshnessInspector for the catalog-derived staleness check that closes that
     * second hole.
     *
     * Firing once on a cold start is cheap and idempotent-ish (an extra backup, a no-op retention
     * pass); never firing is unbounded loss. The asymmetry decides it. Schedules fire in declaration
     * order within a tick, so a config that declares backup before drill still has an artifact to
     * drill against on a first-ever boot.
     */
    private function isDue(BackupSchedule $schedule, ScheduleState $state, DateTimeImmutable $now): bool
    {
        if ($state->lastFiredAt === null) {
            return true;
        }

        return $this->evaluator->nextDueAfter($schedule->cron, $state->lastFiredAt) <= $now;
    }

    private function fire(BackupSchedule $schedule, ScheduleState $state, DateTimeImmutable $now): string
    {
        try {
            $result = $this->runner->execute($schedule);
            // run-once-on-recovery: re-base to now so missed windows don't storm.
            $this->state->put($schedule->name, $state->firedAt($now));

            return 'fired: ' . $result;
        } catch (\Throwable $e) {
            $attempts = $state->consecutiveFailures + 1;

            if ($attempts >= $this->maxRetries) {
                // Give up until the next cron occurrence, and raise a dead-man alert.
                $this->emitFailure($schedule, sprintf('gave up after %d attempts: %s', $attempts, $e->getMessage()), $now);
                $this->state->put($schedule->name, $state->firedAt($now));

                return 'failed (retries exhausted): ' . $e->getMessage();
            }

            $retryAfter = $now->modify('+' . $this->backoffSeconds($attempts) . ' seconds');
            $this->state->put($schedule->name, $state->failed($retryAfter));

            return sprintf('failed (attempt %d, retry after %s): %s', $attempts, $retryAfter->format('H:i:s'), $e->getMessage());
        }
    }

    private function backoffSeconds(int $attempt): int
    {
        // Exponential backoff, capped at one hour.
        return (int) min(3600, $this->baseBackoffSeconds * (2 ** ($attempt - 1)));
    }

    private function emitFailure(BackupSchedule $schedule, string $error, DateTimeImmutable $now): void
    {
        $this->events?->emit(BackupEvent::failed($schedule->engine, $schedule->environment, $error, $now));
    }

    public function scheduleCount(): int
    {
        return count($this->schedules);
    }
}
