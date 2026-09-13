<?php

declare(strict_types=1);

namespace Vortos\Backup\Drill\Check;

use Vortos\Backup\Catalog\WalVolumeReadModelInterface;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Drill\InvariantCheck;
use Vortos\Backup\Drill\InvariantResult;
use Vortos\Backup\Pitr\PostgresWalFetcher;
use Vortos\Backup\Pitr\XlogLongPageHeader;

/**
 * Asserts that archived WAL can actually be fetched back and is a valid segment.
 *
 * WHY THIS EXISTS AS A SEPARATE CHECK. The drill restores a `logical_full`, and a logical dump needs
 * no WAL at all — so a completely broken WAL pipeline produced a PASSING drill. That is not
 * hypothetical: WAL archiving was changed to gzip the segments before encrypting them, which
 * rewrote the entire read path, and the weekly drill would have gone on reporting green whatever
 * that change did to recoverability. The one signal that exists to prove "we can recover" was blind
 * to half of what recovery depends on.
 *
 * It is worth being precise about what this does and does not prove. It exercises the real
 * `restore_command` path — {@see PostgresWalFetcher}, including store resolution, decryption and
 * decompression — and checks that what comes back is a well-formed segment of exactly the right
 * size, in an unbroken sequence. It does NOT perform a replay: it does not start Postgres in
 * recovery and roll the log forward. A full PITR drill is a bigger thing and belongs beside this,
 * not instead of it. What this catches is the regression class that is otherwise silent — a codec,
 * envelope, or routing change that makes segments unreadable — and it catches it weekly rather than
 * at the moment someone needs them.
 *
 * THREE THINGS ARE CHECKED, and each maps to a way recovery fails quietly:
 *
 *  - **Size.** A restored segment must be exactly `wal_segment_size`. A short one is the dangerous
 *    case: it is a well-formed PREFIX of real WAL, so Postgres replays it, stops early, and reports
 *    a successful recovery to an earlier instant than was asked for.
 *  - **Magic.** The leading bytes must be an XLOG page magic. Catches a segment that is the
 *    right length but is not WAL — ciphertext written through unencrypted, or a partial inflate
 *    padded out.
 *  - **Continuity.** Segment names must form an unbroken hex sequence. A gap means replay stops
 *    there, and Postgres reads a missing segment as "the archive ends here" rather than as an error.
 */
final class WalRestorableInvariant implements InvariantCheck
{
    /**
     * High byte of XLOG_PAGE_MAGIC, the uint16 every WAL page starts with.
     *
     * It is stored LITTLE-ENDIAN, so PostgreSQL 18's 0xD118 appears on disk as the bytes `18 D1` —
     * confirmed by hexdumping a real restored segment, after an earlier version of this check
     * compared against `D1 18` and rejected every valid segment in production. The unit test agreed
     * with it, because the fixture was built from the same wrong constant; only real bytes settled
     * it. Hence this now checks against a value read from production rather than reasoned about.
     *
     * A FAMILY check on the high byte, not an exact per-version list. The low byte is bumped on
     * every on-disk format change, so pinning exact values means a major upgrade fails this
     * invariant on correct data — and the failure would read as "backups are broken" during the one
     * week someone is already nervous. The high byte has been 0xD0/0xD1 across every version this
     * framework supports, which is specific enough to reject ciphertext, a partial inflate, or a
     * file that simply is not WAL, while surviving an upgrade.
     *
     * @var list<string>
     */
    private const XLOG_MAGIC_HIGH_BYTES = ["\xD1", "\xD0"];

    /**
     * Used only to walk segment NAMES when the newest segment has no long page header to say how large a
     * segment is — which a real segment always has. Never used as the expected file length.
     */
    private const FALLBACK_NAMING_SEGMENT_BYTES = 16 * 1024 * 1024;

    public function __construct(
        private readonly PostgresWalFetcher $fetcher,
        private readonly WalVolumeReadModelInterface $catalog,
        private readonly string $environment,
        /** Enough to be meaningful, few enough to keep the weekly drill quick. */
        private readonly int $sampleSize = 5,
        /**
         * Expected segment length. Null — the production setting — takes it from each segment's own
         * long page header, because `wal_segment_size` belongs to the cluster and is fixed at initdb.
         * This used to default to 16 MiB; when production was rebuilt at 2 MiB every valid segment was
         * reported as the wrong size and the drill failed on a correct archive (FB-65).
         */
        private readonly ?int $segmentBytes = null,
    ) {}

    public function name(): string
    {
        return 'wal_restorable';
    }

    public function check(array $connectionParams): InvariantResult
    {
        // One row, then names derived by walking backwards. Deliberately not a list query over the
        // WAL slice — that set is unbounded and hydrating it is what once exhausted the worker's
        // memory limit and left retention dead for days.
        $newest = $this->catalog->newestWalSegmentName(DatabaseEngine::Postgres, $this->environment);

        // No WAL is not a failure — continuous archiving is optional, and a host that has not
        // enabled it must not be told its backups are broken. Saying so explicitly matters though:
        // "no WAL configured" and "WAL verified" are different states and the report should not
        // blur them, which is exactly how the row_count invariant once passed while asserting
        // nothing at all.
        if ($newest === null) {
            return InvariantResult::pass($this->name(), 'no archived WAL for this environment');
        }

        $dir = sys_get_temp_dir() . '/vortos-wal-drill-' . bin2hex(random_bytes(6));
        if (!mkdir($dir, 0o700, true) && !is_dir($dir)) {
            return InvariantResult::fail($this->name(), "cannot create scratch directory {$dir}");
        }

        try {
            // The newest segment is fetched first because its header decides everything else: how
            // long a whole segment is on this cluster, and where segment numbers wrap when walking
            // back to its predecessors.
            $newestPath = $dir . '/' . $newest;

            try {
                $this->fetcher->fetch($newest, $newestPath, $this->environment);
            } catch (\Throwable $e) {
                return InvariantResult::fail($this->name(), sprintf('%s: fetch failed (%s)', $newest, $e->getMessage()));
            }

            $declared = XlogLongPageHeader::fromFile($newestPath);
            $expected = $this->segmentBytes ?? $declared?->segmentBytes;

            if ($expected === null) {
                return InvariantResult::fail(
                    $this->name(),
                    sprintf('%s: no valid WAL long page header, so the segment size cannot be established', $newest),
                );
            }

            $namingBytes = $declared->segmentBytes ?? self::FALLBACK_NAMING_SEGMENT_BYTES;
            $segments    = $this->recentSegmentNames($newest, $namingBytes);
            $failures    = [];

            foreach ($segments as $name) {
                $path = $dir . '/' . $name;

                if ($name !== $newest) {
                    try {
                        $this->fetcher->fetch($name, $path, $this->environment);
                    } catch (\Throwable $e) {
                        $failures[] = sprintf('%s: fetch failed (%s)', $name, $e->getMessage());
                        continue;
                    }
                }

                $size = is_file($path) ? (int) filesize($path) : 0;
                if ($size !== $expected) {
                    $failures[] = sprintf('%s: restored %d bytes, expected %d', $name, $size, $expected);
                    continue;
                }

                $handle = fopen($path, 'rb');
                $magic  = $handle !== false ? (string) fread($handle, 2) : '';
                if ($handle !== false) {
                    fclose($handle);
                }

                // Byte 1, not byte 0: the magic is a little-endian uint16, so the version-stable
                // high byte is the SECOND one on disk.
                $high = strlen($magic) === 2 ? $magic[1] : '';
                if (!in_array($high, self::XLOG_MAGIC_HIGH_BYTES, true)) {
                    $failures[] = sprintf('%s: not a WAL page (leading bytes 0x%s)', $name, bin2hex($magic));
                    continue;
                }

                // One archive prefix holds one cluster's WAL, and one cluster has one segment size. A
                // segment declaring another size came from somewhere else and would corrupt a replay.
                if ($this->segmentBytes === null) {
                    $header = XlogLongPageHeader::fromFile($path);
                    if ($header === null) {
                        $failures[] = sprintf('%s: no valid WAL long page header', $name);
                    } elseif ($header->segmentBytes !== $expected) {
                        $failures[] = sprintf('%s: declares %d-byte segments, expected %d', $name, $header->segmentBytes, $expected);
                    }
                }
            }

            if (($gap = $this->firstGap($segments, $namingBytes)) !== null) {
                $failures[] = sprintf('sequence gap between %s and %s', $gap[0], $gap[1]);
            }

            if ($failures !== []) {
                return InvariantResult::fail($this->name(), implode('; ', $failures));
            }

            return InvariantResult::pass(
                $this->name(),
                sprintf('%d segments fetched, %d bytes each, sequence contiguous', count($segments), $expected),
            );
        } catch (\Throwable $e) {
            return InvariantResult::fail($this->name(), $e->getMessage());
        } finally {
            $this->cleanup($dir);
        }
    }

    /**
     * The newest segments, oldest-first, ending at $newest.
     *
     * Newest rather than a random sample: a codec or routing regression affects what is being
     * written NOW, and old segments would keep passing on the previous format long after new ones
     * became unreadable — the check would go green for as long as the retention window.
     *
     * @return list<string>
     */
    private function recentSegmentNames(string $newest, int $segmentBytes): array
    {
        $names = [];
        for ($i = $this->sampleSize - 1; $i >= 0; $i--) {
            $name = $this->offsetSegment($newest, -$i, $segmentBytes);
            if ($name !== null) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Walk a segment name backwards by $delta positions.
     *
     * A WAL name is 24 hex chars: 8 timeline, 8 logical id, 8 segment number. The segment number does
     * NOT simply decrement across a logical-id boundary: one logical id holds 4 GiB / wal_segment_size
     * segments, so it wraps at 0xFF for 16 MiB but at 0x7FF for 2 MiB. This borrows explicitly, at the
     * wrap the cluster actually uses, rather than treating the last 16 chars as one integer. Getting
     * that wrong synthesises names that never existed and reports a fetch failure as a WAL fault.
     */
    private function offsetSegment(string $name, int $delta, int $segmentBytes): ?string
    {
        if (strlen($name) !== 24 || $delta > 0) {
            return $delta === 0 ? $name : null;
        }

        $timeline = substr($name, 0, 8);
        $logical  = hexdec(substr($name, 8, 8));
        $segment  = hexdec(substr($name, 16, 8));
        $lastSeg  = XlogLongPageHeader::segmentsPerLogicalIdFor($segmentBytes) - 1;

        for ($i = 0; $i < -$delta; $i++) {
            if ($segment === 0) {
                if ($logical === 0) {
                    return null;
                }
                --$logical;
                // The full range, not one short. Pre-9.3 Postgres skipped the last segment of each
                // logical id; every supported version uses it, so stopping short would synthesise a
                // name one short of the real predecessor and report a phantom gap every 4 GiB of WAL.
                $segment = $lastSeg;
            } else {
                --$segment;
            }
        }

        return sprintf('%s%08X%08X', $timeline, $logical, $segment);
    }

    /**
     * @param list<string> $segments oldest-first
     *
     * @return array{string, string}|null
     */
    private function firstGap(array $segments, int $segmentBytes): ?array
    {
        for ($i = 1, $n = count($segments); $i < $n; $i++) {
            if ($this->offsetSegment($segments[$i], -1, $segmentBytes) !== $segments[$i - 1]) {
                return [$segments[$i - 1], $segments[$i]];
            }
        }

        return null;
    }

    private function cleanup(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }
}
