<?php

declare(strict_types=1);

namespace Vortos\Backup\Restore\Driver\Postgres;

use PDO;
use RuntimeException;
use Throwable;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Drill\Container\ContainerHandle;
use Vortos\Backup\Drill\Container\ContainerRuntimeInterface;
use Vortos\Backup\Drill\Container\TarStream;
use Vortos\Backup\Pitr\PitrRecoveryRecorder;
use Vortos\Backup\Pitr\WalArchiveFeeder;
use Vortos\Backup\Restore\Capability\RestoreTargetCapability;
use Vortos\Backup\Restore\RestoreRequest;
use Vortos\Backup\Restore\RestoreTargetInterface;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

/**
 * Restores a `physical_base` backup and rolls it forward through archived WAL — real
 * point-in-time recovery, and the only restore path in this framework that proves the WAL chain.
 *
 * WHY A SECOND POSTGRES TARGET rather than a capability switch inside {@see PostgresRestoreTarget}.
 * The two share an engine and nothing else. A logical restore streams a `pg_dump` archive into
 * `pg_restore` over a connection to a database that already exists; this lays a tar of a DATA
 * DIRECTORY onto disk, writes recovery configuration beside it, boots a postmaster over it, and
 * feeds it a log to replay. Different inputs, different lifecycle, different failure modes — and
 * the existing target honestly declares `PointInTime => false`, which is what has kept the weekly
 * drill from ever picking up a base backup it could not consume. Making that flag conditional would
 * turn one class into two implementations sharing a name.
 *
 * WHAT THIS PROVES, precisely, because a drill that overstates itself is worse than none:
 *  - the base backup decrypts, and is a complete, startable data directory;
 *  - archived WAL decrypts, decompresses and is accepted by a real postmaster as valid log;
 *  - recovery replays FORWARD from the base to the end of the archive and promotes;
 *  - the promoted cluster satisfies the same invariants a logical restore has to.
 * The last three are what no existing check covers: {@see \Vortos\Backup\Drill\Check\WalRestorableInvariant}
 * verifies that segments can be FETCHED and are well-formed, but never replays one.
 *
 * The instance is left running and reachable at `$request->destinationDsn` so the drill's invariant
 * checks can interrogate it; teardown belongs to the provisioner that created the container.
 */
#[AsDriver('postgres-pitr')]
final class PostgresPitrRestoreTarget implements RestoreTargetInterface
{
    /** Options the provisioner must supply; see {@see \Vortos\Backup\Drill\DrillEnvironment::$options}. */
    public const OPTION_CONTAINER_ID = 'container_id';
    public const OPTION_CONTAINER_NAME = 'container_name';
    public const OPTION_PGDATA = 'pgdata';
    public const OPTION_RECORDER = 'pitr_recorder';

    public function __construct(
        private readonly ContainerRuntimeInterface $runtime,
        private readonly WalArchiveFeeder $feeder,
        private readonly int $readyTimeoutSeconds = 900,
    ) {}

    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([
            RestoreTargetCapability::StreamingRestore->value => true,
            // A base backup IS the whole cluster. There is no "restore into an existing database"
            // mode to offer, and claiming one would invite a caller to expect the wrong semantics.
            RestoreTargetCapability::CleanRestore->value => false,
            RestoreTargetCapability::PointInTime->value => true,
        ]);
    }

    public function engine(): DatabaseEngine
    {
        return DatabaseEngine::Postgres;
    }

    public function restore(iterable $chunks, RestoreRequest $request): void
    {
        $handle = $this->handleFrom($request);
        $pgdata = $this->option($request, self::OPTION_PGDATA);
        $recorder = $request->options[self::OPTION_RECORDER] ?? null;

        if (!$recorder instanceof PitrRecoveryRecorder) {
            throw new RuntimeException(sprintf(
                'A point-in-time restore requires a %s in the request options: its evidence — how far '
                . 'the log actually replayed — is the only thing that distinguishes a real recovery '
                . 'from a base backup that started up having replayed nothing.',
                PitrRecoveryRecorder::class,
            ));
        }

        // The control files go in FIRST, into a container that has been created but never started.
        // Ordering is the whole reason this target needs a create/start split: PostgreSQL reads
        // recovery.signal and postgresql.auto.conf once, at boot, and a data directory that gains
        // them afterwards is simply a cluster that started normally at the base backup's instant.
        $this->installControlFiles($handle, $pgdata);

        // The base backup is already a tar — `pg_basebackup --format=tar` — so it is forwarded to
        // Docker byte for byte. Never parsed, never buffered, never written to disk: the decrypted
        // plaintext of the entire production database goes straight from the object store into the
        // container's data directory in bounded memory.
        $this->runtime->putArchive($handle, $pgdata, $chunks);

        $this->installRecoveryConfig($handle, $pgdata);

        $startedAt = time();
        $this->runtime->start($handle);

        try {
            $outcome = $this->feeder->feed(
                $handle,
                fn (): ?array => $this->probe($request->destinationDsn),
                // One second of slack: container timestamps and this clock are not the same clock,
                // and a `since` filter that is a hair too late silently drops the first WAL request,
                // which then times out inside the container with nothing to explain it.
                $startedAt - 1,
            );
        } catch (Throwable $e) {
            throw new RuntimeException(
                $e->getMessage() . "\n\nRecovery log tail:\n" . $this->logTail($handle, $startedAt - 1),
                0,
                $e,
            );
        }

        $recorder->record($outcome);

        // The cluster has promoted, but "promoted" and "accepting connections" are not the same
        // instant, and the invariant checks run against a DSN the moment this returns.
        $this->awaitConnectable($request->destinationDsn, $handle, $startedAt);
    }

    /**
     * The data directory, the `restore_command` script, and the directories it reads — all placed
     * before the container starts.
     *
     * Uploaded to `/` with the paths carried inside the tar, because Docker's archive endpoint
     * requires its `path` argument to already exist, while the extractor happily creates
     * directories from the entries themselves.
     *
     * THE DATA DIRECTORY HAS TO BE CREATED HERE, which is not obvious and cost a production drill
     * to learn. The official image declares `PGDATA=/var/lib/postgresql/18/docker` but only
     * `/var/lib/postgresql` exists in the image; the `18/docker` path is created by the entrypoint
     * at startup. A point-in-time restore writes the cluster in BEFORE anything starts, so it must
     * create that path itself — otherwise the base backup upload fails with a bare Docker 404 that
     * reads like a broken artifact rather than a missing directory.
     *
     * Mode 0700 and uid 70 from the outset: PostgreSQL rejects a data directory with group or world
     * permissions, and the image's own `/var/lib/postgresql` is mode 1777 for its initdb step, so a
     * child inheriting anything laxer would fail at startup complaining about permissions.
     */
    private function installControlFiles(ContainerHandle $handle, string $pgdata): void
    {
        $vortosDir = ltrim(\dirname(WalArchiveFeeder::SCRIPT_PATH), '/');

        $tar = (new TarStream())
            ->addDirectory(ltrim($pgdata, '/'), 0o700)
            ->addDirectory($vortosDir, 0o755)
            ->addFile(
                ltrim(WalArchiveFeeder::SCRIPT_PATH, '/'),
                WalArchiveFeeder::fetchScript($this->readyTimeoutSeconds),
                0o755,
            )
            ->addDirectory(ltrim(WalArchiveFeeder::STAGING_DIR, '/'), 0o700)
            ->addDirectory(ltrim(WalArchiveFeeder::ABSENT_DIR, '/'), 0o700);

        $this->runtime->putArchive($handle, '/', [$tar->toString()]);
    }

    /**
     * `recovery.signal` plus the settings that turn a restored data directory into a recovery.
     *
     * Written to `postgresql.auto.conf`, which PostgreSQL reads LAST — so these override whatever
     * the production cluster's own `postgresql.conf` said, and that file travels inside the base
     * backup. Two of the overrides are not tuning but containment:
     *
     *  - `archive_mode = off`. The production configuration in the tar points `archive_command` at
     *    a shared volume that does not exist here. Left on, a drill would spend its life failing to
     *    archive — and on a host where that path DID exist, a throwaway cluster would be writing
     *    into the real WAL archive it was restored from.
     *  - `lc_messages = C`. The feeder reads `redo starts at` and `redo done at` out of the server
     *    log to bracket the replay. Those strings are translated, so without pinning the locale the
     *    evidence this drill produces would depend on the image's language settings.
     */
    private function installRecoveryConfig(ContainerHandle $handle, string $pgdata): void
    {
        $conf = <<<CONF
        # Written by the Vortos PITR restore drill. PostgreSQL reads postgresql.auto.conf last, so
        # these win over the production configuration carried inside the base backup.
        restore_command = {$this->restoreCommandValue()}
        recovery_target_timeline = 'current'
        recovery_target_action = 'promote'

        # Containment: never let a throwaway recovery write to the real archive.
        archive_mode = 'off'
        archive_command = ''

        # Stable, parseable server log — the feeder reads recovery progress from it.
        lc_messages = 'C'
        log_destination = 'stderr'
        logging_collector = 'off'

        # Reachable from the drill network, and read-only queryable during recovery.
        listen_addresses = '*'
        hot_standby = 'on'

        # This cluster is destroyed minutes from now and never serves a request, so durability is
        # traded for replay speed. Safe here precisely because nothing recovers FROM this instance.
        fsync = 'off'
        full_page_writes = 'off'
        synchronous_commit = 'off'

        CONF;

        $tar = (new TarStream())
            // Empty, and its presence is the entire message: this file is what tells PostgreSQL to
            // enter archive recovery instead of starting up as a normal cluster.
            ->addFile('recovery.signal', '', 0o600)
            ->addFile('postgresql.auto.conf', $conf, 0o600);

        $this->runtime->putArchive($handle, $pgdata, [$tar->toString()]);
    }

    private function restoreCommandValue(): string
    {
        return WalArchiveFeeder::restoreCommand();
    }

    /**
     * Ask the recovering cluster where it is, or report that it is not answering yet.
     *
     * Returning null rather than throwing is the contract: for most of a recovery the postmaster
     * refuses connections, and that is the normal state rather than an error.
     *
     * @return array{in_recovery: bool, replay_lsn: ?string, current_lsn: ?string, timeline: ?string}|null
     */
    private function probe(string $dsn): ?array
    {
        try {
            $pdo = $this->connect($dsn, 3);

            $statement = $pdo->query(
                'SELECT pg_is_in_recovery() AS in_recovery, '
                . 'pg_last_wal_replay_lsn()::text AS replay_lsn, '
                . '(SELECT timeline_id FROM pg_control_checkpoint()) AS timeline',
            );

            $row = $statement === false ? null : $statement->fetch(PDO::FETCH_ASSOC);

            if (!\is_array($row)) {
                return null;
            }

            $inRecovery = (bool) ($row['in_recovery'] ?? false);

            // pg_current_wal_lsn() is only callable once the cluster has left recovery; asking for
            // it during replay raises an error that would abort the probe rather than answer it.
            $currentLsn = null;
            if (!$inRecovery) {
                $lsnStatement = $pdo->query('SELECT pg_current_wal_lsn()::text');
                $value = $lsnStatement === false ? null : $lsnStatement->fetchColumn();
                $currentLsn = \is_string($value) ? $value : null;
            }

            return [
                'in_recovery' => $inRecovery,
                'replay_lsn' => \is_string($row['replay_lsn'] ?? null) ? $row['replay_lsn'] : null,
                'current_lsn' => $currentLsn,
                'timeline' => isset($row['timeline']) ? (string) $row['timeline'] : null,
            ];
        } catch (Throwable) {
            return null;
        }
    }

    private function awaitConnectable(string $dsn, ContainerHandle $handle, int $startedAt): void
    {
        $deadline = microtime(true) + 120;
        $lastError = 'timed out';

        while (microtime(true) < $deadline) {
            try {
                $this->connect($dsn, 3)->query('SELECT 1');

                return;
            } catch (Throwable $e) {
                $lastError = $e->getMessage();
                usleep(300_000);
            }
        }

        throw new RuntimeException(sprintf(
            "The recovered cluster promoted but never accepted connections: %s\n\nRecovery log tail:\n%s",
            $lastError,
            $this->logTail($handle, $startedAt - 1),
        ));
    }

    private function connect(string $dsn, int $timeoutSeconds): PDO
    {
        $p = $this->parseDsn($dsn);

        return new PDO(
            sprintf(
                'pgsql:host=%s;port=%d;dbname=%s;connect_timeout=%d',
                $p['host'],
                $p['port'],
                $p['dbname'],
                $timeoutSeconds,
            ),
            $p['user'],
            $p['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => $timeoutSeconds],
        );
    }

    /**
     * The tail of the recovery log, attached to any failure.
     *
     * A PITR failure is almost always explained by something PostgreSQL said while starting up —
     * a permissions complaint about the data directory, a missing segment, an unreadable
     * configuration. Without this the drill reports a timeout and the log dies with the container
     * that teardown is about to remove.
     */
    private function logTail(ContainerHandle $handle, int $since, int $lines = 40): string
    {
        try {
            $log = $this->runtime->logsSince($handle, $since);
        } catch (Throwable $e) {
            return '(container log unavailable: ' . $e->getMessage() . ')';
        }

        $all = array_values(array_filter(explode("\n", $log), static fn (string $l): bool => trim($l) !== ''));

        return implode("\n", \array_slice($all, -$lines));
    }

    private function handleFrom(RestoreRequest $request): ContainerHandle
    {
        $id = $this->option($request, self::OPTION_CONTAINER_ID);
        $name = $this->option($request, self::OPTION_CONTAINER_NAME);

        return new ContainerHandle($id, $name, $name);
    }

    private function option(RestoreRequest $request, string $key): string
    {
        $value = $request->options[$key] ?? null;

        if (!\is_string($value) || $value === '') {
            throw new RuntimeException(sprintf(
                "A point-in-time restore requires the '%s' option, which the drill provisioner supplies. "
                . 'Its absence means this target was reached through a path that cannot describe a '
                . 'recovery environment — a logical restore request, most likely.',
                $key,
            ));
        }

        return $value;
    }

    /**
     * @return array{host:string, port:int, user:string, password:string, dbname:string}
     */
    private function parseDsn(string $dsn): array
    {
        $parsed = parse_url($dsn);
        if ($parsed === false || !isset($parsed['scheme'])) {
            throw new RuntimeException(sprintf('Invalid Postgres DSN: %s', $dsn));
        }

        return [
            'host' => $parsed['host'] ?? 'localhost',
            'port' => $parsed['port'] ?? 5432,
            'user' => $parsed['user'] ?? 'postgres',
            'password' => isset($parsed['pass']) ? urldecode($parsed['pass']) : '',
            'dbname' => ltrim($parsed['path'] ?? '/postgres', '/'),
        ];
    }
}
