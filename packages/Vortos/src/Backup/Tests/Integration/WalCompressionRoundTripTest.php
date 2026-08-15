<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Integration;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Vortos\Backup\Crypto\EnvelopeStreamCipher;
use Vortos\Backup\Domain\CompressionCodec;
use Vortos\Backup\Driver\ObjectStore\ObjectStoreBackupStore;
use Vortos\Backup\Pitr\PostgresWalArchiver;
use Vortos\Backup\Pitr\PostgresWalFetcher;
use Vortos\Backup\Pitr\WalCompressionSettings;
use Vortos\Backup\Port\BackupStoreRegistry;
use Vortos\Backup\Service\EncryptionSeam\EnvelopeStreamTransformFactory;
use Vortos\Backup\Tests\Support\FakeKeyProvider;
use Vortos\Backup\Tests\Support\FixedClock;
use Vortos\Backup\Tests\Support\InMemoryCatalogRepository;
use Vortos\Backup\Tests\Support\InMemoryObjectStore;

/**
 * Archive → fetch, across every combination of encryption and compression a real store holds.
 *
 * WHY ALL FOUR. Compression and encryption were each switched on at a different instant, and an
 * object store keeps everything written on both sides of both instants. A recovery that spans a
 * cutover replays segments in every combination, and the read path has no catalog to consult —
 * the catalog lives inside the database being restored. So the format must be self-describing from
 * the bytes alone, and the only honest way to assert that is to write each combination and read it
 * back with a fetcher that was told nothing about how it was written.
 *
 * A miss here is silent, which is the whole point: Postgres treats a missing or short segment as
 * the end of the archive, so a fetcher that mishandled a combination would not error — it would
 * stop replaying and report a successful recovery to the wrong point in time.
 */
final class WalCompressionRoundTripTest extends TestCase
{
    private string $walFile;

    /** Sparse like a real forced-switch segment: a little log, then padding. Kept at 1 MiB so the suite stays quick. */
    private string $segment;

    protected function setUp(): void
    {
        $this->segment = str_pad("\x98\xD0\x01\x00" . random_bytes(64 * 1024), 1024 * 1024, "\0");
        $this->walFile = sys_get_temp_dir() . '/wal-' . bin2hex(random_bytes(4));
        file_put_contents($this->walFile, $this->segment);
    }

    protected function tearDown(): void
    {
        @unlink($this->walFile);
    }

    /** @return array{PostgresWalArchiver, PostgresWalFetcher} */
    private function pipeline(InMemoryObjectStore $object, bool $encrypted, bool $compressed): array
    {
        $stores = new BackupStoreRegistry(new ServiceLocator([
            'object-store' => fn () => new ObjectStoreBackupStore($object),
        ]));

        $cipher      = new EnvelopeStreamCipher();
        $keyProvider = $encrypted ? new FakeKeyProvider() : null;
        $transforms  = $keyProvider !== null
            ? new EnvelopeStreamTransformFactory($keyProvider, $cipher, 'fake-age')
            : null;

        $settings = $compressed
            ? new WalCompressionSettings(CompressionCodec::Gzip, 6)
            : WalCompressionSettings::disabled();

        $archiver = new PostgresWalArchiver(
            $stores,
            new InMemoryCatalogRepository(),
            new FixedClock(new DateTimeImmutable('now')),
            'object-store',
            'backups',
            $transforms,
            $cipher,
            $keyProvider,
            $settings,
        );

        // NOTE the fetcher is given no codec and no knowledge of how the segment was written.
        $fetcher = new PostgresWalFetcher($stores, ['object-store'], 'backups', $cipher, $keyProvider);

        return [$archiver, $fetcher];
    }

    /**
     * @return iterable<string, array{bool, bool}>
     */
    public static function combinations(): iterable
    {
        yield 'legacy: plain, uncompressed'      => [false, false];
        yield 'encrypted only (pre-compression)' => [true, false];
        yield 'compressed only'                  => [false, true];
        yield 'encrypted and compressed'         => [true, true];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('combinations')]
    public function test_a_segment_survives_the_round_trip(bool $encrypted, bool $compressed): void
    {
        $object                = new InMemoryObjectStore();
        [$archiver, $fetcher]  = $this->pipeline($object, $encrypted, $compressed);

        $archiver->archive($this->walFile, 'production');

        $destination = sys_get_temp_dir() . '/wal-out-' . bin2hex(random_bytes(4));
        try {
            $written = $fetcher->fetch(basename($this->walFile), $destination, 'production');

            $this->assertSame(strlen($this->segment), $written, 'restored segment is the wrong length');
            $this->assertSame(
                $this->segment,
                file_get_contents($destination),
                'restored segment differs from the archived one',
            );
        } finally {
            @unlink($destination);
        }
    }

    /**
     * The point of the exercise, asserted end to end rather than on the codec in isolation: what
     * actually lands in the bucket is a fraction of 16 MiB.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('compressedCombinations')]
    public function test_the_stored_object_is_a_fraction_of_the_segment(bool $encrypted): void
    {
        $object       = new InMemoryObjectStore();
        [$archiver, ] = $this->pipeline($object, $encrypted, true);

        $archiver->archive($this->walFile, 'production');

        $stored = strlen((string) reset($object->objects));
        $ratio  = strlen($this->segment) / $stored;

        $this->assertGreaterThan(5.0, $ratio, sprintf(
            'stored object is %d bytes for a %d byte segment (%.1fx) — compression is not reaching the store',
            $stored,
            strlen($this->segment),
            $ratio,
        ));
    }

    /** @return iterable<string, array{bool}> */
    public static function compressedCombinations(): iterable
    {
        yield 'plaintext'  => [false];
        yield 'encrypted'  => [true];
    }

    /**
     * Idempotency is defined on segment CONTENT, and archive_command depends on it: a segment that
     * cannot be re-archived successfully is one Postgres retries forever, eventually filling pg_wal
     * and stopping writes. Compression varies the stored bytes (as encryption already did), so the
     * comparison has to reverse the whole pipeline before digesting.
     */
    public function test_re_archiving_a_compressed_segment_is_a_successful_noop(): void
    {
        $object               = new InMemoryObjectStore();
        [$archiver, ]         = $this->pipeline($object, true, true);

        $archiver->archive($this->walFile, 'production');
        $after = count($object->objects);

        $archiver->archive($this->walFile, 'production');

        $this->assertCount($after, $object->objects);
    }

    /**
     * Turning compression on must not break the idempotency check for the ~28,000 segments already
     * archived without it. This is the upgrade path, and getting it wrong wedges archive_command on
     * the first segment Postgres happens to retry.
     */
    public function test_re_archiving_over_an_uncompressed_object_still_compares_equal(): void
    {
        $object = new InMemoryObjectStore();

        [$legacy, ] = $this->pipeline($object, true, false);
        $legacy->archive($this->walFile, 'production');
        $after = count($object->objects);

        [$upgraded, ] = $this->pipeline($object, true, true);
        $upgraded->archive($this->walFile, 'production');

        $this->assertCount($after, $object->objects, 'a compression upgrade must not rewrite existing objects');
    }
}
