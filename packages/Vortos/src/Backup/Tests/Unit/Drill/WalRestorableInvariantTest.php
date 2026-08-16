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

/**
 * The drill's WAL coverage — the check that closes the gap where a logical-dump drill passed while
 * the entire WAL pipeline was unreadable.
 */
final class WalRestorableInvariantTest extends TestCase
{
    private const SEGMENT_BYTES = 4096; // stand-in for 16 MiB; keeps the suite fast
    private const NEWEST        = '0000000100000073000000AA';

    private function catalog(?string $newest): WalVolumeReadModelInterface
    {
        return new class ($newest) implements WalVolumeReadModelInterface {
            public function __construct(private readonly ?string $newest) {}

            public function walVolumeSince(DatabaseEngine $e, string $env, DateTimeImmutable $f): array
            {
                return ['segments' => 0, 'bytes' => 0];
            }

            public function newestWalSegmentName(DatabaseEngine $e, string $env): ?string
            {
                return $this->newest;
            }
        };
    }

    /** @param array<string, string> $objects keyed by segment name */
    private function invariant(array $objects, ?string $newest = self::NEWEST, int $sample = 3): WalRestorableInvariant
    {
        $store = new InMemoryObjectStore();
        foreach ($objects as $name => $body) {
            $store->objects['backups/production/postgres/wal/' . $name] = $body;
        }

        $fetcher = new PostgresWalFetcher(
            new BackupStoreRegistry(new ServiceLocator(['s' => fn () => new ObjectStoreBackupStore($store)])),
            ['s'],
            'backups',
        );

        return new WalRestorableInvariant($fetcher, $this->catalog($newest), 'production', $sample, self::SEGMENT_BYTES);
    }

    /** A well-formed segment: PG18 page magic then padding to full size. */
    private function segment(): string
    {
        return str_pad("\xD1\x18\x00\x00", self::SEGMENT_BYTES, "\0");
    }

    /** @return array<string, string> the newest $n segments, correctly named */
    private function contiguous(int $n): array
    {
        $out  = [];
        $base = hexdec(substr(self::NEWEST, 16, 8));
        for ($i = 0; $i < $n; $i++) {
            $out[sprintf('%s%08X', substr(self::NEWEST, 0, 16), $base - $i)] = $this->segment();
        }

        return $out;
    }

    public function test_passes_when_segments_fetch_back_whole_and_contiguous(): void
    {
        $result = $this->invariant($this->contiguous(3))->check([]);

        $this->assertTrue($result->passed, $result->detail);
        $this->assertStringContainsString('3 segments fetched', $result->detail);
    }

    /**
     * THE DANGEROUS CASE. A short segment is a well-formed PREFIX of real WAL — Postgres replays it,
     * stops early, and reports a successful recovery to an earlier instant than was asked for.
     */
    public function test_fails_on_a_short_segment(): void
    {
        $objects = $this->contiguous(3);
        $objects[self::NEWEST] = substr($this->segment(), 0, 512);

        $result = $this->invariant($objects)->check([]);

        $this->assertFalse($result->passed);
        $this->assertStringContainsString('512 bytes', $result->detail);
    }

    /** Right length, wrong content — e.g. ciphertext that never got decrypted. */
    public function test_fails_when_the_bytes_are_not_a_wal_page(): void
    {
        $objects = $this->contiguous(3);
        $objects[self::NEWEST] = str_pad('NOTWAL', self::SEGMENT_BYTES, "\0");

        $result = $this->invariant($objects)->check([]);

        $this->assertFalse($result->passed);
        $this->assertStringContainsString('not a WAL page', $result->detail);
    }

    public function test_fails_when_a_segment_is_missing_entirely(): void
    {
        $objects = $this->contiguous(3);
        unset($objects[array_key_last($objects)]);

        $result = $this->invariant($objects)->check([]);

        $this->assertFalse($result->passed);
        $this->assertStringContainsString('fetch failed', $result->detail);
    }

    /**
     * No WAL is a legitimate state (continuous archiving is optional) and must not be reported as a
     * failure — but the detail has to say so, rather than reading like a verification that happened.
     */
    public function test_no_archived_wal_passes_but_says_so(): void
    {
        $result = $this->invariant([], null)->check([]);

        $this->assertTrue($result->passed);
        $this->assertStringContainsString('no archived WAL', $result->detail);
    }

    /**
     * Segment numbers wrap at 0xFF within a logical id, they do not borrow like a plain integer.
     * Walking back from ...00000000 must produce ...FFFFFFFF-1 in the PREVIOUS logical id, and
     * treating the low 16 hex chars as one number would synthesise names that never existed —
     * reported as a fetch failure, i.e. a phantom WAL fault once per 4 GB of log.
     */
    public function test_walks_backwards_correctly_across_a_logical_id_boundary(): void
    {
        $newest = '00000001000000740000 0000';
        $newest = str_replace(' ', '', $newest);

        $objects = [
            $newest                    => $this->segment(),
            '0000000100000073000000FF' => $this->segment(),
        ];

        $result = $this->invariant($objects, $newest, 2)->check([]);

        $this->assertTrue($result->passed, $result->detail);
    }
}
