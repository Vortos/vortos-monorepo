<?php

declare(strict_types=1);

namespace Vortos\Backup\Pitr;

use DateTimeImmutable;
use Throwable;
use Vortos\Backup\DR\ArchiverStatusReaderInterface;

/**
 * Keeps PostgreSQL's archive_timeout honoured after a restart — the RPO must not depend on a timer nobody wakes.
 *
 * WHY (RC-10, measured on production 2026-09-15, PostgreSQL 18.6). After a clean shutdown and start, the
 * checkpointer computes its first sleep while startup is still in recovery, when the archive_timeout branch of
 * that computation is skipped, so it sleeps the whole checkpoint_timeout (30 minutes there). A clean start requests
 * no end-of-recovery checkpoint and the end of recovery wakes the checkpointer only on promotion, so nothing
 * shortens that sleep: for up to checkpoint_timeout after EVERY start — a host reboot, an OOM restart, a deliberate
 * recreate — archive_timeout forces no WAL switch. Segments archive only when they fill, the RPO silently stretches
 * (a 902 s archive gap against a 60 s timeout), and the archiver reports nothing wrong because nothing failed.
 * One CHECKPOINT wakes the checkpointer and switching resumes on the next archive_timeout. Reproduced with the stock
 * image and plain command-line flags, so it is the server, not how it was configured.
 *
 * WHAT IT DOES. Once per tick of the backup runtime, and at most once per postmaster start, it issues exactly that
 * CHECKPOINT — only when the stranded timer is the explanation: archiving is on with a positive archive_timeout, the
 * server is a primary, written WAL has gone unarchived for longer than archive_timeout plus a grace, archive_command
 * is not failing, and no checkpoint has completed since the postmaster started. It does not force switches itself
 * (pg_switch_wal on its own clock would ship a segment a minute from an idle cluster, which PostgreSQL deliberately
 * avoids): it restores PostgreSQL's own mechanism and gets out of the way. Every value it needs comes from the
 * server, so there is no second copy of archive_timeout to drift.
 *
 * If the wake is refused, the recovery-point-objective probe still pages on the exposure it measures.
 */
final class CheckpointerWaker
{
    /**
     * Slack beyond archive_timeout before the timer is judged stranded: one archive_command copy and one
     * backup-runtime tick of observation latency, so a switch that is merely in flight never triggers a checkpoint.
     */
    public const GRACE_SECONDS = 60;

    public function __construct(
        private readonly CheckpointerTimersReaderInterface $timers,
        private readonly ArchiverStatusReaderInterface $archiver,
        private readonly CheckpointRequesterInterface $requester,
    ) {}

    public function ensure(DateTimeImmutable $now): CheckpointerWakeResult
    {
        try {
            $timers = $this->timers->read();
            $status = $this->archiver->read();
        } catch (Throwable $e) {
            return new CheckpointerWakeResult(CheckpointerWakeOutcome::Indeterminate, null, $e::class);
        }

        if (!$timers->archivingEnabled || $timers->archiveTimeoutSeconds <= 0 || $timers->inRecovery) {
            return new CheckpointerWakeResult(CheckpointerWakeOutcome::NotApplicable, null, null);
        }

        if (!$status->hasUnarchivedWal()) {
            return new CheckpointerWakeResult(CheckpointerWakeOutcome::Honoured, 0, null);
        }

        // Measured from the later of the last archive and this start: the timer cannot have run before the postmaster
        // did, so a cluster that was down (or never archived) gets PostgreSQL's full archive_timeout plus grace to
        // switch on its own before it is judged stranded.
        $since = max($status->lastArchivedAt?->getTimestamp() ?? 0, $timers->postmasterStartedAt->getTimestamp());
        $exposure = max(0, $now->getTimestamp() - $since);

        if ($exposure <= $timers->archiveTimeoutSeconds + self::GRACE_SECONDS) {
            return new CheckpointerWakeResult(CheckpointerWakeOutcome::Honoured, $exposure, null);
        }

        if ($status->isFailing()) {
            return new CheckpointerWakeResult(CheckpointerWakeOutcome::ArchivingFailing, $exposure, null);
        }

        if ($timers->checkpointedSinceStart()) {
            return new CheckpointerWakeResult(CheckpointerWakeOutcome::AlreadyAwake, $exposure, null);
        }

        try {
            $this->requester->checkpoint();
        } catch (Throwable $e) {
            return new CheckpointerWakeResult(CheckpointerWakeOutcome::WakeFailed, $exposure, $e::class);
        }

        return new CheckpointerWakeResult(CheckpointerWakeOutcome::Woken, $exposure, null);
    }
}
