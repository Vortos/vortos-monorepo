<?php

declare(strict_types=1);

namespace Vortos\Backup\PgBackRest;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use RuntimeException;
use Throwable;
use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\DR\RecoveryObjectives;
use Vortos\Backup\Drill\Container\ContainerHandle;
use Vortos\Backup\Drill\Container\ContainerRuntimeInterface;
use Vortos\Backup\Drill\Container\ContainerSpec;
use Vortos\Backup\Drill\Driver\Postgres\ContainerizedDatabaseProvisioner;
use Vortos\Backup\Drill\DrillOutcome;
use Vortos\Backup\Drill\DrillReport;
use Vortos\Backup\Drill\DrillReportStoreInterface;
use Vortos\Backup\Drill\InvariantCheck;
use Vortos\Backup\Drill\InvariantResult;
use Vortos\Backup\Event\BackupEvent;
use Vortos\Backup\Event\BackupEventSinkInterface;
use Vortos\Backup\Health\WalFileSizeResolverInterface;
use Vortos\Backup\Pitr\PitrRecoveryOutcome;

/**
 * Restores the newest pgBackRest backup into a disposable server, replays the archive to its end, and
 * proves the result with the same invariants as every other drill.
 *
 * WHAT IT PROVES. Recovery as it would be done for real: pgBackRest's own `restore`, from the
 * repository, with the image production runs (same pgBackRest, same server binaries), then WAL fetched
 * by pgBackRest's `archive-get` until the archive ends, then promotion. The drill passes only if the
 * server came up as a primary having replayed through the newest segment archived when it started.
 *
 * THREE GUARDS, each because the alternative is quietly wrong:
 *  - **archive_mode off**, on the command line AND written by `restore --archive-mode=off`. The image's
 *    baked configuration archives into the production repository; a promoted drill server would push a
 *    new timeline there, and a later "restore latest" could follow it.
 *  - **The credential is a read-only bind**, read by root and handed to pgBackRest after dropping to the
 *    server's user. The backup node orchestrating the drill never holds it — passing it as container
 *    environment would require reading it first.
 *  - **Never published.** Reached by name on the drill network only, and removed in `finally`; swept by
 *    the same label as every other drill container, so one killed mid-flight is gone by the next drill.
 */
final class PgBackRestDrill
{
    public const ORPHAN_LABEL = ContainerizedDatabaseProvisioner::ORPHAN_LABEL;
    private const CREDENTIAL_PATH = '/run/vortos/pgbackrest.env';
    /** Lines a client sees while the server boots; they are the server working, not failing. */
    private const BENIGN_STARTUP = '/the database system is (starting up|shutting down|not yet accepting connections)|not yet accepting connections|consistent recovery state has not been yet reached/';

    /**
     * @param iterable<InvariantCheck> $invariantChecks
     */
    public function __construct(
        private readonly PgBackRestClient $client,
        private readonly ContainerRuntimeInterface $runtime,
        private readonly PromotedServerProbeInterface $probe,
        /** The connection the backups are taken through: a physical restore reproduces its roles exactly. */
        private readonly Connection $source,
        private readonly WalFileSizeResolverInterface $segmentSize,
        private readonly DrillReportStoreInterface $reports,
        private readonly BackupEventSinkInterface $events,
        private readonly ClockInterface $clock,
        private readonly RecoveryObjectives $objectives,
        private readonly iterable $invariantChecks = [],
        private readonly ?string $network = null,
        private readonly int $timeoutSeconds = 5400,
        private readonly int $pollMicroseconds = 2_000_000,
    ) {}

    public function run(string $environment): DrillReport
    {
        $start = $this->clock->now();
        $handle = null;
        $artifact = 'pgbackrest:unknown';

        try {
            $info = $this->client->info();
            if (!$info->isOk()) {
                throw new RuntimeException(sprintf('the repository reports status %d: %s', $info->statusCode, $info->statusMessage));
            }
            $backup = $info->newest() ?? throw new RuntimeException('the repository holds no valid backup to restore');
            $archiveMax = $info->archiveMax ?? throw new RuntimeException('the repository holds no archived WAL');
            $artifact = 'pgbackrest:' . $backup->label;
            $segmentBytes = $this->segmentSize->segmentBytes();

            $this->runtime->removeOrphans(self::ORPHAN_LABEL);
            $handle = $this->runtime->create(new ContainerSpec(
                image: $this->client->databaseImage(),
                name: 'vortos-pgbackrest-drill-' . bin2hex(random_bytes(6)),
                labels: [self::ORPHAN_LABEL => '1'],
                network: $this->network,
                command: [$this->bootScript()],
                entrypoint: ['/bin/sh', '-c'],
                user: '0:0',
                readOnlyBinds: [$this->client->repository()->repositoryEnvFile => self::CREDENTIAL_PATH],
            ));
            $this->runtime->start($handle);
            $bootedAt = microtime(true);

            $connection = $this->connectionTo($handle);
            $endLsn = $this->awaitPromotion($handle, $connection, $start->getTimestamp());
            $promotedMs = (int) round((microtime(true) - $bootedAt) * 1000);
            $restoreMs = self::restoreMs($this->runtime->logsSince($handle, $start->getTimestamp() - 5)) ?? 0;

            $results = [];
            foreach ($this->invariantChecks as $check) {
                $results[] = $check->check($connection);
            }
            $results[] = $this->replayed($backup, $archiveMax, $endLsn, $segmentBytes, max(0, $promotedMs - $restoreMs));

            $rtoMs = $this->elapsedMs($start);
            $passed = array_reduce($results, static fn (bool $carry, InvariantResult $r): bool => $carry && $r->passed, true);
            $report = new DrillReport(
                $this->id(),
                DatabaseEngine::Postgres,
                $environment,
                $artifact,
                $start,
                $rtoMs,
                $passed ? ($this->objectives->rtoExceeded($rtoMs) ? DrillOutcome::OverObjective : DrillOutcome::Passed) : DrillOutcome::Failed,
                $results,
                $passed ? null : 'One or more invariant checks failed.',
                BackupKind::PhysicalBase,
            );
        } catch (Throwable $e) {
            $report = new DrillReport(
                $this->id(),
                DatabaseEngine::Postgres,
                $environment,
                $artifact,
                $start,
                $this->elapsedMs($start),
                DrillOutcome::Failed,
                [],
                $e->getMessage(),
                BackupKind::PhysicalBase,
            );
        } finally {
            if ($handle !== null) {
                try {
                    $this->runtime->remove($handle);
                } catch (Throwable) {
                    // The next drill's orphan sweep removes it; a teardown failure must not hide the verdict.
                }
            }
        }

        $this->reports->save($report);
        $now = $this->clock->now();
        $this->events->emit(match ($report->outcome) {
            DrillOutcome::Passed => BackupEvent::drillSucceeded(DatabaseEngine::Postgres, $environment, $report->rtoMs, $now),
            DrillOutcome::OverObjective => BackupEvent::drillOverObjective(DatabaseEngine::Postgres, $environment, $report->rtoMs, $this->objectives->rtoSeconds, $now),
            DrillOutcome::Failed => BackupEvent::drillFailed(DatabaseEngine::Postgres, $environment, $report->error ?? 'invariant failure', $now),
        });

        return $report;
    }

    /**
     * Root reads the credential, then everything runs as the server's own user (uid 70). `restore
     * --type=default` replays to the end of the archive; `--archive-mode=off` and the command-line
     * setting both keep the drill from archiving into production's repository.
     */
    private function bootScript(): string
    {
        $stanza = $this->client->repository()->stanza;

        return 'set -eu; set -a; . ' . self::CREDENTIAL_PATH . '; set +a; '
            . "exec su postgres -s /bin/sh -c '"
            . 'pgbackrest --stanza=' . $stanza . ' --process-max=4 --archive-mode=off restore --type=default'
            . ' && exec postgres -c config_file=/etc/postgresql/postgresql.conf -c archive_mode=off'
            . "'";
    }

    /**
     * @param array{host: string, port: int, user: string, password: string, dbname: string} $connection
     */
    private function awaitPromotion(ContainerHandle $handle, array $connection, int $sinceUnix): string
    {
        $deadline = microtime(true) + $this->timeoutSeconds;

        while (true) {
            $lsn = $this->probe->promotedReplayLsn($connection);
            if ($lsn !== null) {
                return $lsn;
            }

            $logs = $this->runtime->logsSince($handle, $sinceUnix - 5);
            $fatal = array_values(array_filter(
                preg_split('/\R/', $logs) ?: [],
                static fn (string $line): bool => preg_match('/\b(FATAL|PANIC):|ERROR: \[\d+\]/', $line) === 1
                    && preg_match(self::BENIGN_STARTUP, $line) !== 1,
            ));
            if ($fatal !== []) {
                throw new RuntimeException('the drill server failed to recover: ' . implode(' | ', \array_slice($fatal, -5)));
            }

            if (microtime(true) >= $deadline) {
                throw new RuntimeException(sprintf('the drill server did not finish recovery within %d s', $this->timeoutSeconds));
            }

            usleep($this->pollMicroseconds);
        }
    }

    private function replayed(PgBackRestBackupSet $backup, string $archiveMax, string $endLsn, int $segmentBytes, int $replayMs): InvariantResult
    {
        $stop = WalFileName::parse($backup->walStop)->sequence($segmentBytes);
        $end = WalFileName::containing($endLsn, WalFileName::parse($archiveMax)->timeline, $segmentBytes);
        $max = WalFileName::parse($archiveMax)->sequence($segmentBytes);

        $outcome = new PitrRecoveryOutcome(
            segmentsServed: max(0, $end->sequence($segmentBytes) - $stop),
            startLsn: $backup->lsnStop,
            endLsn: $endLsn,
            lastSegment: $end->name,
            reachedEndOfWal: $end->sequence($segmentBytes) >= $max,
            recoveryMs: $replayMs,
        );

        // Reaching the end is the claim. Replaying zero segments past the backup is still a pass when the
        // archive ended there: a quiet cluster has nothing more to replay, and the restore itself is proven.
        return $outcome->reachedEndOfWal
            ? InvariantResult::pass('wal_replayed', $outcome->summary())
            : InvariantResult::fail('wal_replayed', sprintf('recovery stopped at %s, before the newest archived segment %s: %s', $end->name, $archiveMax, $outcome->summary()));
    }

    /** pgBackRest's own measure of the restore, from its closing line. */
    public static function restoreMs(string $logs): ?int
    {
        return preg_match('/restore command end: completed successfully \((\d+)ms\)/', $logs, $m) === 1 ? (int) $m[1] : null;
    }

    /**
     * @return array{host: string, port: int, user: string, password: string, dbname: string}
     */
    private function connectionTo(ContainerHandle $handle): array
    {
        $p = $this->source->getParams();
        $dbname = (string) ($p['dbname'] ?? '');
        if ($dbname === '') {
            throw new RuntimeException('the source connection names no database; a physical restore has only the source cluster\'s databases');
        }

        return [
            'host' => $handle->host,
            'port' => 5432,
            'user' => (string) ($p['user'] ?? ''),
            'password' => (string) ($p['password'] ?? ''),
            'dbname' => $dbname,
        ];
    }

    private function elapsedMs(\DateTimeImmutable $start): int
    {
        return max(0, (int) round(((float) $this->clock->now()->format('U.u') - (float) $start->format('U.u')) * 1000));
    }

    private function id(): string
    {
        return 'drill-' . $this->clock->now()->format('Ymd\THis') . '-' . bin2hex(random_bytes(4));
    }
}
