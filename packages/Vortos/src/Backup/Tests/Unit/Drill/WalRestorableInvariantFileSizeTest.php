<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\Drill;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Vortos\Backup\Catalog\WalVolumeReadModelInterface;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Drill\Check\WalRestorableInvariant;
use Vortos\Backup\Driver\ObjectStore\ObjectStoreBackupStore;
use Vortos\Backup\Pitr\PostgresWalFetcher;
use Vortos\Backup\Port\BackupStoreRegistry;
use Vortos\Backup\Tests\Support\InMemoryObjectStore;
use Vortos\Backup\Tests\Support\WalFileFixture;

/**
 * FB-65. Production was rebuilt at wal_segsize=2MB and the drill then failed every valid segment as
 * "restored 2097152 bytes, expected 16777216". The segment size must come from the segments.
 */
final class WalRestorableInvariantFileSizeTest extends TestCase
{
    private const MIB2 = 2 * 1024 * 1024;

    /** @param array<string, string> $objects keyed by segment name */
    private function invariant(array $objects, string $newest, int $sample): WalRestorableInvariant
    {
        $store = new InMemoryObjectStore();
        foreach ($objects as $name => $body) {
            $store->objects['backups/production/postgres/wal/' . $name] = $body;
        }

        $catalog = new class ($newest) implements WalVolumeReadModelInterface {
            public function __construct(private readonly string $newest) {}

            public function walVolumeSince(DatabaseEngine $e, string $env, DateTimeImmutable $f): array
            {
                return ['segments' => 0, 'bytes' => 0];
            }

            public function newestWalSegmentName(DatabaseEngine $e, string $env): ?string
            {
                return $this->newest;
            }
        };

        $fetcher = new PostgresWalFetcher(
            new BackupStoreRegistry(new ServiceLocator(['s' => fn () => new ObjectStoreBackupStore($store)])),
            ['s'],
            'backups',
        );

        // No explicit segment size: the production configuration.
        return new WalRestorableInvariant($fetcher, $catalog, 'production', $sample);
    }

    public function test_passes_on_a_2_mib_cluster_across_a_logical_id_boundary(): void
    {
        // At 2 MiB one logical id holds 2048 segments, so the predecessor of ...00000001 00000000 is
        // ...00000000 000007FF — not 000000FF, which is where 16 MiB arithmetic would look.
        $names = ['0000000100000000000007FE', '0000000100000000000007FF', '000000010000000100000000', '000000010000000100000001'];
        $objects = array_fill_keys($names, WalFileFixture::segment(self::MIB2));

        $result = $this->invariant($objects, '000000010000000100000001', 4)->check([]);

        self::assertTrue($result->passed, $result->detail);
        self::assertStringContainsString('4 segments fetched, 2097152 bytes each', $result->detail);
    }

    public function test_fails_when_a_segment_declares_a_different_size_than_the_chain(): void
    {
        $objects = [
            '000000010000000000000010' => WalFileFixture::segment(self::MIB2, declaredBytes: 16 * 1024 * 1024),
            '000000010000000000000011' => WalFileFixture::segment(self::MIB2),
        ];

        $result = $this->invariant($objects, '000000010000000000000011', 2)->check([]);

        self::assertFalse($result->passed);
        self::assertStringContainsString('000000010000000000000010: declares 16777216-byte segments, expected 2097152', $result->detail);
    }

    public function test_fails_when_the_newest_segment_has_no_long_header_to_size_the_chain_by(): void
    {
        $objects = ['000000010000000000000011' => str_pad("\x18\xD1\x00\x00", self::MIB2, "\0")];

        $result = $this->invariant($objects, '000000010000000000000011', 1)->check([]);

        self::assertFalse($result->passed);
        self::assertStringContainsString('segment size cannot be established', $result->detail);
    }

    public function test_a_truncated_segment_still_fails_against_the_declared_size(): void
    {
        $objects = [
            '000000010000000000000010' => substr(WalFileFixture::segment(self::MIB2), 0, self::MIB2 - 8192),
            '000000010000000000000011' => WalFileFixture::segment(self::MIB2),
        ];

        $result = $this->invariant($objects, '000000010000000000000011', 2)->check([]);

        self::assertFalse($result->passed);
        self::assertStringContainsString('restored 2088960 bytes, expected 2097152', $result->detail);
    }
}
