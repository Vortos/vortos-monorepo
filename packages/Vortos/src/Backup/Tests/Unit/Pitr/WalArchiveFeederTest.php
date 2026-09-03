<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\Pitr;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Vortos\Backup\Drill\Container\ContainerHandle;
use Vortos\Backup\Driver\ObjectStore\ObjectStoreBackupStore;
use Vortos\Backup\Pitr\PostgresWalFetcher;
use Vortos\Backup\Pitr\WalArchiveFeeder;
use Vortos\Backup\Port\BackupStoreRegistry;
use Vortos\Backup\Tests\Support\RecordingContainerRuntime;
use Vortos\Backup\Tests\Support\InMemoryObjectStore;

/**
 * The request/response channel between a recovering PostgreSQL and the sidecar that holds the keys.
 *
 * Driven through a REAL {@see PostgresWalFetcher} over an in-memory store rather than a mock, so
 * these exercise the actual store resolution and envelope handling a recovery depends on. The
 * container's half of the conversation is scripted: each poll appends the next `VORTOS-WAL-WANT`
 * line once the previous segment has been delivered, which is exactly how the real script behaves.
 */
final class WalArchiveFeederTest extends TestCase
{
    private const SEGMENT_BYTES = 4096; // stand-in for 16 MiB; keeps the fixtures small
    private const PREFIX = 'backups/production/postgres/wal/';

    private function segmentName(int $n): string
    {
        return sprintf('00000001000000000000%04X', $n);
    }

    /**
     * @param list<int> $available segment numbers the archive holds, starting at 1
     */
    private function feeder(
        RecordingContainerRuntime $runtime,
        array $available,
        int $maxSegments = 100,
        int $timeout = 10,
    ): WalArchiveFeeder {
        $store = new InMemoryObjectStore();
        foreach ($available as $n) {
            $store->objects[self::PREFIX . $this->segmentName($n)] = str_repeat('W', self::SEGMENT_BYTES);
        }

        $fetcher = new PostgresWalFetcher(
            new BackupStoreRegistry(new ServiceLocator(['s' => fn () => new ObjectStoreBackupStore($store)])),
            ['s'],
            'backups',
        );

        return new WalArchiveFeeder(
            $runtime,
            $fetcher,
            'production',
            $maxSegments,
            $timeout,
            self::SEGMENT_BYTES,
            sys_get_temp_dir(),
        );
    }

    /**
     * Script a container that asks for segment 1, then 2, ... and stops when one comes back absent.
     */
    private function scriptedRuntime(int $lastWanted): RecordingContainerRuntime
    {
        $runtime = new RecordingContainerRuntime();
        $runtime->log = ['LOG:  redo starts at 0/3000028'];
        $wanted = 0;

        $runtime->onPoll = function (RecordingContainerRuntime $rt) use (&$wanted, $lastWanted): void {
            $names = $rt->uploadedNames();
            $current = $this->segmentName($wanted);

            // Ask for the next segment only once the previous exchange has completed — a delivery
            // (marked by its .ready file) or an absent marker.
            $answered = $wanted === 0
                || \in_array($current . '.ready', $names, true)
                || \in_array($current, $names, true);

            if (!$answered || $wanted > $lastWanted) {
                return;
            }

            $wanted++;
            $rt->log[] = 'VORTOS-WAL-WANT ' . $this->segmentName($wanted);
        };

        return $runtime;
    }

    /** @param callable(int): bool $promoteAfter */
    private function probe(RecordingContainerRuntime $runtime, string $endLsn = '0/5000100'): callable
    {
        return static function () use ($runtime, $endLsn): ?array {
            // The cluster promotes once it has been told a segment is missing — the archive is
            // exhausted, which is how a real point-in-time recovery ends.
            $sawAbsent = false;
            foreach ($runtime->uploads as $upload) {
                if ($upload['path'] === WalArchiveFeeder::ABSENT_DIR) {
                    $sawAbsent = true;
                }
            }

            return [
                'in_recovery' => !$sawAbsent,
                'replay_lsn' => $sawAbsent ? null : $endLsn,
                'current_lsn' => $sawAbsent ? $endLsn : null,
                'timeline' => '1',
            ];
        };
    }

    public function testServesEverySegmentTheClusterAsksForAndStopsAtTheEndOfTheArchive(): void
    {
        $runtime = $this->scriptedRuntime(4);
        $outcome = $this->feeder($runtime, [1, 2, 3])
            ->feed(new ContainerHandle('c', 'c', 'c'), $this->probe($runtime), time() - 1);

        self::assertSame(3, $outcome->segmentsServed);
        self::assertSame($this->segmentName(3), $outcome->lastSegment);
        self::assertTrue($outcome->reachedEndOfWal);
        self::assertTrue($outcome->replayed());
        self::assertSame('0/3000028', $outcome->startLsn);
    }

    /**
     * Data first, marker second — and the marker carries the byte count.
     *
     * Docker extracts an upload into a live filesystem, so a segment is briefly visible while still
     * being written. Moving a partial segment into pg_wal is the worst failure available here: it is
     * a valid PREFIX of real WAL, so recovery replays it, stops early, and reports success at an
     * earlier instant than the one asked for.
     */
    public function testEachSegmentIsDeliveredAsAPartFileFollowedByAReadyMarker(): void
    {
        $runtime = $this->scriptedRuntime(2);
        $this->feeder($runtime, [1])
            ->feed(new ContainerHandle('c', 'c', 'c'), $this->probe($runtime), time() - 1);

        $names = $runtime->uploadedNames();
        $seg = $this->segmentName(1);

        self::assertSame(
            array_search($seg . '.part', $names, true) + 1,
            array_search($seg . '.ready', $names, true),
            'the ready marker must be uploaded immediately after, and never before, the bytes',
        );

        $marker = array_values(array_filter(
            $runtime->uploads,
            static fn (array $u): bool => str_contains($u['bytes'], (string) self::SEGMENT_BYTES),
        ));
        self::assertNotEmpty($marker, 'the ready marker must carry the expected byte count');
    }

    /**
     * A timeline history file is absent on a cluster that never diverged. Counting that as the end
     * of the archive would let a recovery that replayed NOTHING claim it reached the last instant.
     */
    public function testAMissingTimelineHistoryFileIsNotTheEndOfTheArchive(): void
    {
        $runtime = new RecordingContainerRuntime();
        $runtime->log = ['LOG:  redo starts at 0/3000028', 'VORTOS-WAL-WANT 00000002.history'];

        $outcome = $this->feeder($runtime, [])
            ->feed(new ContainerHandle('c', 'c', 'c'), $this->probe($runtime), time() - 1);

        self::assertSame(0, $outcome->segmentsServed);
        self::assertFalse($outcome->reachedEndOfWal);
        self::assertFalse($outcome->replayed(), 'nothing was replayed, and the outcome must say so');
    }

    /**
     * The budget exists so a base backup too far behind the archive fails as ITSELF, rather than as
     * a drill that runs for hours and is discovered by a timeout.
     */
    public function testRefusesToServeMoreSegmentsThanTheConfiguredBudget(): void
    {
        $runtime = $this->scriptedRuntime(10);

        $this->expectExceptionMessageMatches('/refused to serve more than 2 WAL segments/');

        $this->feeder($runtime, [1, 2, 3, 4], maxSegments: 2)
            ->feed(new ContainerHandle('c', 'c', 'c'), $this->probe($runtime), time() - 1);
    }

    /**
     * A cluster that never leaves recovery has proved nothing, and must not be reported as a pass.
     */
    public function testFailsWhenRecoveryNeverCompletes(): void
    {
        $runtime = new RecordingContainerRuntime();
        $runtime->log = ['LOG:  redo starts at 0/3000028'];

        $this->expectExceptionMessageMatches('/did not complete within/');

        $this->feeder($runtime, [], timeout: 1)
            ->feed(new ContainerHandle('c', 'c', 'c'), static fn (): ?array => [
                'in_recovery' => true,
                'replay_lsn' => '0/3000028',
                'current_lsn' => null,
                'timeline' => '1',
            ], time() - 1);
    }

    /**
     * "Not in the archive" and "could not be read" must never be conflated. An unreadable segment
     * answered as absence ends recovery early and reports a clean restore to the wrong instant.
     */
    public function testAFetchFailureThatIsNotAMissIsFatal(): void
    {
        $runtime = new RecordingContainerRuntime();
        $runtime->log = ['VORTOS-WAL-WANT ' . $this->segmentName(1)];

        $store = new InMemoryObjectStore();
        // Present, but the wrong length — a corrupt or truncated object, not a missing one.
        $store->objects[self::PREFIX . $this->segmentName(1)] = 'too short';

        $fetcher = new PostgresWalFetcher(
            new BackupStoreRegistry(new ServiceLocator(['s' => fn () => new ObjectStoreBackupStore($store)])),
            ['s'],
            'backups',
        );

        $feeder = new WalArchiveFeeder($runtime, $fetcher, 'production', 100, 5, self::SEGMENT_BYTES, sys_get_temp_dir());

        $this->expectExceptionMessageMatches('/refusing to replay a truncated segment/');

        $feeder->feed(new ContainerHandle('c', 'c', 'c'), static fn (): ?array => null, time() - 1);
    }
}
