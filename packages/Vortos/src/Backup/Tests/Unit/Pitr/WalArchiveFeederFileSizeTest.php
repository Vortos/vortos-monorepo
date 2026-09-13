<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\Pitr;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Vortos\Backup\Drill\Container\ContainerHandle;
use Vortos\Backup\Driver\ObjectStore\ObjectStoreBackupStore;
use Vortos\Backup\Pitr\PostgresWalFetcher;
use Vortos\Backup\Pitr\WalArchiveFeeder;
use Vortos\Backup\Port\BackupStoreRegistry;
use Vortos\Backup\Tests\Support\InMemoryObjectStore;
use Vortos\Backup\Tests\Support\RecordingContainerRuntime;
use Vortos\Backup\Tests\Support\WalFileFixture;

/**
 * FB-65. With the size hardcoded at 16 MiB, every segment of a cluster built at 2 MiB was refused as
 * truncated — the PITR drill failed on a correct archive, and a real point-in-time restore through the
 * same feeder would have too. These run the feeder in its production configuration: no configured size.
 */
final class WalArchiveFeederFileSizeTest extends TestCase
{
    private const MIB2   = 2 * 1024 * 1024;
    private const PREFIX = 'backups/production/postgres/wal/';

    /** @param array<string, string> $objects keyed by archived file name */
    private function feeder(RecordingContainerRuntime $runtime, array $objects): WalArchiveFeeder
    {
        $store = new InMemoryObjectStore();
        foreach ($objects as $name => $body) {
            $store->objects[self::PREFIX . $name] = $body;
        }

        $fetcher = new PostgresWalFetcher(
            new BackupStoreRegistry(new ServiceLocator(['s' => fn () => new ObjectStoreBackupStore($store)])),
            ['s'],
            'backups',
        );

        return new WalArchiveFeeder(
            runtime: $runtime,
            fetcher: $fetcher,
            environment: 'production',
            maxSegments: 100,
            timeoutSeconds: 5,
            fetchAttempts: 1,
            scratchDir: sys_get_temp_dir(),
        );
    }

    private function runtimeAsking(string ...$names): RecordingContainerRuntime
    {
        $runtime = new RecordingContainerRuntime();
        $runtime->log = ['LOG:  redo starts at 0/3000028'];
        foreach ($names as $name) {
            $runtime->log[] = 'VORTOS-WAL-WANT ' . $name;
        }

        return $runtime;
    }

    /** Promotes once the archive has answered "absent" — how a real recovery reaches the end of the log. */
    private function promotesAtEndOfArchive(RecordingContainerRuntime $runtime): callable
    {
        return static function () use ($runtime): ?array {
            $sawAbsent = false;
            foreach ($runtime->uploads as $upload) {
                if ($upload['path'] === WalArchiveFeeder::ABSENT_DIR) {
                    $sawAbsent = true;
                }
            }

            return [
                'in_recovery' => !$sawAbsent,
                'replay_lsn'  => $sawAbsent ? null : '0/4000000',
                'current_lsn' => $sawAbsent ? '0/4000100' : null,
                'timeline'    => '1',
            ];
        };
    }

    private function feed(RecordingContainerRuntime $runtime, array $objects): \Vortos\Backup\Pitr\PitrRecoveryOutcome
    {
        return $this->feeder($runtime, $objects)
            ->feed(new ContainerHandle('c', 'c', 'c'), $this->promotesAtEndOfArchive($runtime), time() - 1);
    }

    public function test_serves_a_2_mib_cluster_sized_by_its_own_headers(): void
    {
        $runtime = $this->runtimeAsking('000000010000000000000003', '000000010000000000000004', '000000010000000000000005');

        $outcome = $this->feed($runtime, [
            '000000010000000000000003' => WalFileFixture::segment(self::MIB2),
            '000000010000000000000004' => WalFileFixture::segment(self::MIB2),
        ]);

        self::assertSame(2, $outcome->segmentsServed);
        self::assertTrue($outcome->reachedEndOfWal);
        self::assertContains('000000010000000000000004.ready', $runtime->uploadedNames());
    }

    public function test_refuses_a_segment_shorter_than_its_header_declares(): void
    {
        $runtime = $this->runtimeAsking('000000010000000000000003');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('restored to 2088960 bytes, expected 2097152');

        $this->feed($runtime, [
            '000000010000000000000003' => substr(WalFileFixture::segment(self::MIB2), 0, self::MIB2 - 8192),
        ]);
    }

    public function test_refuses_a_segment_without_a_long_page_header(): void
    {
        $runtime = $this->runtimeAsking('000000010000000000000003');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not start with a valid WAL long page header');

        $this->feed($runtime, [
            '000000010000000000000003' => str_pad("\x18\xD1\x00\x00", self::MIB2, "\0"),
        ]);
    }

    public function test_refuses_to_mix_segment_sizes_within_one_recovery(): void
    {
        $runtime = $this->runtimeAsking('000000010000000000000003', '000000010000000000000004');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('refusing to mix WAL from different clusters');

        $this->feed($runtime, [
            '000000010000000000000003' => WalFileFixture::segment(self::MIB2),
            '000000010000000000000004' => WalFileFixture::segment(1024 * 1024),
        ]);
    }

    /**
     * A timeline history file is a few lines of text. Holding it to a segment's length refused a valid
     * one, which would fail recovery on any cluster that has ever been promoted onto a new timeline.
     */
    public function test_a_present_timeline_history_file_is_served_without_being_held_to_segment_length(): void
    {
        $runtime = $this->runtimeAsking('00000002.history', '000000020000000000000003');

        $outcome = $this->feed($runtime, [
            '00000002.history' => "1\t0/3000000\tno recovery target specified\n",
        ]);

        self::assertContains('00000002.history.part', $runtime->uploadedNames());
        self::assertContains('00000002.history.ready', $runtime->uploadedNames());
        self::assertTrue($outcome->reachedEndOfWal);
    }
}
