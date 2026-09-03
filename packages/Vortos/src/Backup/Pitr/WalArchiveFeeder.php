<?php

declare(strict_types=1);

namespace Vortos\Backup\Pitr;

use RuntimeException;
use Throwable;
use Vortos\Backup\Drill\Container\ContainerHandle;
use Vortos\Backup\Drill\Container\ContainerRuntimeInterface;
use Vortos\Backup\Drill\Container\TarStream;

/**
 * Serves archived WAL to a PostgreSQL instance recovering inside a container, and watches it replay.
 *
 * WHY THIS SHAPE. Recovery has to run in a stock `postgres` image, because the backup sidecar
 * carries the PostgreSQL *client* only — there is no `postgres` binary to start. That image has no
 * PHP, so `restore_command` cannot invoke the Vortos CLI, and it has no backup keys, so it could
 * not decrypt a segment even if it could reach the object store. Meanwhile the archive holds
 * segments that are gzip-compressed and AEAD-encrypted, and reading them back correctly is the
 * single most important thing a PITR drill exists to prove.
 *
 * So the same split the ARCHIVE side already uses is mirrored here. Archiving keeps PHP out of the
 * database image with a pure `cp` archive_command and a shipper process that owns the credentials;
 * recovery keeps PHP out of the drill image with a pure-shell `restore_command` and this feeder,
 * which owns the credentials. Neither side asks the database image to know anything about Vortos.
 *
 * The channel between them uses only Docker Engine API calls the least-privilege socket-proxy
 * already permits — verified against the production proxy before this was written, since a design
 * that needed a raw socket or a host bind-mount would not have been shippable:
 *
 *   request   `restore_command` writes `VORTOS-WAL-WANT <segment>` to stderr, which PostgreSQL
 *             folds into its own log, which this reads back with `GET /containers/{id}/logs`.
 *   response  {@see PostgresWalFetcher} resolves the segment across every configured store,
 *             decrypts and inflates it, and this uploads it with `PUT /containers/{id}/archive`.
 *   end       a segment the archive does not hold is answered with an `absent` marker, so the
 *             script exits non-zero and PostgreSQL ends recovery — which is exactly how a real
 *             point-in-time recovery terminates, not a special case invented for the drill.
 *
 * Purely reactive, and that is deliberate. The feeder never computes which segments a recovery will
 * need: it answers what PostgreSQL asks for, in the order PostgreSQL asks. Deriving the chain
 * independently would mean re-implementing WAL name arithmetic and timeline selection, and getting
 * it subtly wrong produces the worst available outcome — a drill that replays a slightly different
 * chain than a real recovery would and reports green.
 *
 * Exactly one segment is staged at a time, so a seven-day chain costs the same disk as a one-hour
 * chain and only the wall clock grows.
 */
final class WalArchiveFeeder
{
    /** Where the feeder stages segments, and where the in-container script looks for them. */
    public const STAGING_DIR = '/vortos/wal';

    /** Markers telling the script a segment is not in the archive — how recovery learns to stop. */
    public const ABSENT_DIR = '/vortos/absent';

    public const SCRIPT_PATH = '/vortos/fetch-wal.sh';

    private const WANT_PREFIX = 'VORTOS-WAL-WANT ';

    public function __construct(
        private readonly ContainerRuntimeInterface $runtime,
        private readonly PostgresWalFetcher $fetcher,
        private readonly string $environment,
        /**
         * Refuses to feed a recovery that will never end. PostgreSQL asks for the next segment
         * forever if something keeps answering, so an unbounded feeder turns a broken archive into a
         * drill that runs until someone notices rather than one that fails.
         */
        private readonly int $maxSegments = 12000,
        private readonly int $timeoutSeconds = 5400,
        private readonly int $segmentBytes = 16 * 1024 * 1024,
        /** Where segments are decrypted before upload; one segment at a time, removed immediately. */
        private readonly ?string $scratchDir = null,
    ) {}

    /**
     * The `restore_command` script, and the recovery settings that drive it.
     *
     * Returned rather than written so the caller decides when it enters the container, and so the
     * contract between the script and this class stays in one file.
     */
    public static function restoreCommand(): string
    {
        return sprintf("'/bin/sh %s \"%%f\" \"%%p\"'", self::SCRIPT_PATH);
    }

    public static function fetchScript(int $waitSeconds = 900): string
    {
        $ticks = max(1, $waitSeconds * 5);
        $staging = self::STAGING_DIR;
        $absent = self::ABSENT_DIR;

        // The `.ready` handshake is not ceremony. Docker extracts an uploaded tar directly into the
        // container filesystem, so a segment file becomes visible while it is still being written.
        // Moving a partially written segment into pg_wal is the most dangerous failure this whole
        // system has: it is a well-formed PREFIX of real WAL, so PostgreSQL replays it, stops early,
        // and reports a SUCCESSFUL recovery to an earlier instant than the one that was asked for.
        // The feeder therefore uploads the bytes under a `.part` name and only then uploads a
        // `.ready` marker containing the expected byte count, which this checks before the move.
        return <<<SH
        #!/bin/sh
        # Vortos PITR restore_command. Runs inside a stock PostgreSQL image with no PHP and no
        # credentials: it asks for a segment and waits for the backup sidecar to deliver it.
        #   \$1 = %f (segment name)   \$2 = %p (destination, relative to PGDATA)
        seg="\$1"
        dest="\$2"

        printf 'VORTOS-WAL-WANT %s\\n' "\$seg" >&2

        i=0
        while [ "\$i" -lt {$ticks} ]; do
            if [ -f "{$absent}/\$seg" ]; then
                # Not an error. PostgreSQL probes past the end of the archive on every recovery, and
                # a non-zero exit here is how it learns where the log stops.
                printf 'VORTOS-WAL-ABSENT %s\\n' "\$seg" >&2
                exit 1
            fi

            if [ -f "{$staging}/\$seg.ready" ]; then
                expected="\$(cat "{$staging}/\$seg.ready" 2>/dev/null || echo 0)"
                actual="\$(wc -c < "{$staging}/\$seg.part" 2>/dev/null || echo 0)"
                if [ "\$expected" != "\$actual" ]; then
                    # Refuse a short segment rather than replay a prefix of one.
                    printf 'VORTOS-WAL-SHORT %s expected=%s actual=%s\\n' "\$seg" "\$expected" "\$actual" >&2
                    rm -f "{$staging}/\$seg.part" "{$staging}/\$seg.ready"
                    exit 1
                fi
                # mv within the same filesystem is atomic, so the destination appears only once it
                # is whole — PostgreSQL treats the presence of that file as "this segment is restored".
                if mv "{$staging}/\$seg.part" "\$dest"; then
                    rm -f "{$staging}/\$seg.ready"
                    printf 'VORTOS-WAL-SERVED %s\\n' "\$seg" >&2
                    exit 0
                fi
                printf 'VORTOS-WAL-MOVE-FAILED %s\\n' "\$seg" >&2
                exit 1
            fi

            sleep 0.2
            i=\$((i+1))
        done

        printf 'VORTOS-WAL-TIMEOUT %s\\n' "\$seg" >&2
        exit 1

        SH;
    }

    /**
     * Feed the recovery until the cluster promotes, and report what it replayed.
     *
     * @param callable(): ?array{in_recovery: bool, replay_lsn: ?string, current_lsn: ?string, timeline: ?string} $probe
     *        connects to the recovering cluster and reports its state, or returns null while it is
     *        not yet accepting connections
     */
    public function feed(ContainerHandle $handle, callable $probe, int $startedAtUnix): PitrRecoveryOutcome
    {
        $deadline = microtime(true) + $this->timeoutSeconds;
        $served = [];
        $absent = [];
        $lastSegment = null;
        $startLsn = null;
        $endLsn = null;
        $timeline = null;
        $reachedEndOfWal = false;
        $promoted = false;
        $begin = microtime(true);
        $seenLines = [];

        while (microtime(true) < $deadline) {
            $log = $this->runtime->logsSince($handle, $startedAtUnix);

            foreach ($this->newLines($log, $seenLines) as $line) {
                // PostgreSQL's own startup messages carry the two LSNs that bracket the replay.
                // `lc_messages` is pinned to C in the recovery configuration precisely so these
                // strings cannot shift under a locale and quietly stop matching.
                if ($startLsn === null && preg_match('/redo starts at ([0-9A-F]+\/[0-9A-F]+)/i', $line, $m) === 1) {
                    $startLsn = strtoupper($m[1]);
                }
                if (preg_match('/redo done at ([0-9A-F]+\/[0-9A-F]+)/i', $line, $m) === 1) {
                    $endLsn = strtoupper($m[1]);
                }
                if (preg_match('/selected new timeline ID: (\d+)/i', $line, $m) === 1) {
                    $timeline = $m[1];
                }

                if (!str_contains($line, self::WANT_PREFIX)) {
                    continue;
                }

                $segment = trim(substr($line, strpos($line, self::WANT_PREFIX) + \strlen(self::WANT_PREFIX)));
                if ($segment === '' || isset($served[$segment]) || isset($absent[$segment])) {
                    continue;
                }

                if (\count($served) >= $this->maxSegments) {
                    throw new RuntimeException(sprintf(
                        'PITR drill refused to serve more than %d WAL segments (asked for %s). The base '
                        . 'backup is too far behind the end of the archive to replay within the configured '
                        . 'budget — take base backups more often, or raise '
                        . 'VORTOS_BACKUP_DRILL_PITR_MAX_SEGMENTS if this window is genuinely intended.',
                        $this->maxSegments,
                        $segment,
                    ));
                }

                if ($this->serve($handle, $segment)) {
                    $served[$segment] = true;
                    $lastSegment = $segment;
                } else {
                    $absent[$segment] = true;
                    // Only a real WAL SEGMENT name means "the archive ends here". PostgreSQL also
                    // probes for timeline history files (`00000002.history`), which are absent on a
                    // cluster that has never diverged — counting those as the end of the archive
                    // would let a recovery that replayed nothing at all claim it had reached it.
                    if ($this->isWalSegmentName($segment)) {
                        $reachedEndOfWal = true;
                    }
                }
            }

            $state = $probe();
            if ($state !== null) {
                $timeline ??= $state['timeline'];
                if ($state['replay_lsn'] !== null) {
                    $endLsn = strtoupper($state['replay_lsn']);
                }
                if ($state['in_recovery'] === false) {
                    // Promoted. `pg_last_wal_replay_lsn()` reads NULL from here on, which is why the
                    // end LSN is captured DURING recovery and from the log, not asked for after.
                    $endLsn ??= $state['current_lsn'] !== null ? strtoupper($state['current_lsn']) : null;
                    $promoted = true;
                    break;
                }
            }

            usleep(200_000);
        }

        if (!$promoted) {
            throw new RuntimeException(sprintf(
                'PITR recovery did not complete within %ds (%d segments served, last %s). The cluster '
                . 'never left recovery, so nothing about the archive has been proved.',
                $this->timeoutSeconds,
                \count($served),
                $lastSegment ?? 'none',
            ));
        }

        return new PitrRecoveryOutcome(
            segmentsServed: \count($served),
            startLsn: $startLsn,
            endLsn: $endLsn,
            lastSegment: $lastSegment,
            reachedEndOfWal: $reachedEndOfWal,
            recoveryMs: (int) round((microtime(true) - $begin) * 1000),
            timeline: $timeline,
        );
    }

    /**
     * Fetch one segment and stage it in the container.
     *
     * @return bool false when the archive does not hold it — the signal that recovery is done
     */
    private function serve(ContainerHandle $handle, string $segment): bool
    {
        $scratch = $this->scratchDir ?? sys_get_temp_dir();
        $path = $scratch . '/' . $segment . '.vortos-feed';

        try {
            $this->fetcher->fetch($segment, $path, $this->environment);
        } catch (ArchivedWalNotFoundException) {
            @unlink($path);
            $this->markAbsent($handle, $segment);

            return false;
        } catch (Throwable $e) {
            @unlink($path);

            // Anything OTHER than "not in the archive" must fail the drill rather than be answered
            // with an absent marker. A decryption failure or an unreachable store answered as
            // absence would end recovery early and report a clean point-in-time restore to the
            // wrong instant — the exact silent failure this drill exists to catch.
            throw new RuntimeException(sprintf(
                "Failed to serve WAL segment '%s' from the archive: %s",
                $segment,
                $e->getMessage(),
            ), 0, $e);
        }

        try {
            $bytes = (int) filesize($path);
            if ($bytes !== $this->segmentBytes) {
                throw new RuntimeException(sprintf(
                    "Archived WAL segment '%s' restored to %d bytes, expected %d — refusing to replay "
                    . 'a truncated segment, which recovers to an earlier instant while reporting success.',
                    $segment,
                    $bytes,
                    $this->segmentBytes,
                ));
            }

            // Data first, marker second, in two uploads. The script waits for the marker, so it can
            // never observe a half-extracted segment; see fetchScript().
            $this->runtime->putArchive(
                $handle,
                self::STAGING_DIR,
                $this->tarOfFile($segment . '.part', $path, $bytes),
            );

            $this->runtime->putArchive(
                $handle,
                self::STAGING_DIR,
                [(new TarStream())->addFile($segment . '.ready', (string) $bytes, 0o600)->toString()],
            );
        } finally {
            @unlink($path);
        }

        return true;
    }

    private function markAbsent(ContainerHandle $handle, string $segment): void
    {
        $this->runtime->putArchive(
            $handle,
            self::ABSENT_DIR,
            [(new TarStream())->addFile($segment, '', 0o600)->toString()],
        );
    }

    /**
     * A tar carrying one 16 MiB segment, streamed from disk rather than built in memory.
     *
     * Building the whole archive as a PHP string would be simpler and would add 16 MiB to the
     * worker's peak memory for no benefit; the retention OOM that left production without pruning
     * for days is a standing reminder of what that habit costs.
     *
     * @return \Generator<int, string, void, void>
     */
    private function tarOfFile(string $name, string $path, int $bytes): \Generator
    {
        yield (new TarStream())->fileHeader($name, $bytes);

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException(sprintf('Cannot read staged WAL segment at %s.', $path));
        }

        try {
            while (!feof($handle)) {
                $chunk = fread($handle, 1024 * 1024);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                yield $chunk;
            }
        } finally {
            fclose($handle);
        }

        yield TarStream::padding($bytes);
        yield TarStream::trailer();
    }

    /**
     * @param array<string, true> $seen
     *
     * @return list<string>
     */
    private function newLines(string $log, array &$seen): array
    {
        $out = [];

        foreach (explode("\n", $log) as $line) {
            $line = rtrim($line, "\r");
            if ($line === '' || isset($seen[$line])) {
                continue;
            }
            $seen[$line] = true;
            $out[] = $line;
        }

        return $out;
    }

    /** A WAL segment name is exactly 24 hex characters: timeline, logical id, segment number. */
    private function isWalSegmentName(string $name): bool
    {
        return preg_match('/^[0-9A-F]{24}$/i', $name) === 1;
    }
}
