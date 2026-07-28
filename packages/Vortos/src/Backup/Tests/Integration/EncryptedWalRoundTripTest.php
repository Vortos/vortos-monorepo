<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Integration;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Vortos\Backup\Crypto\EnvelopeHeader;
use Vortos\Backup\Crypto\EnvelopeStreamCipher;
use Vortos\Backup\Domain\Exception\BackupException;
use Vortos\Backup\Driver\ObjectStore\ObjectStoreBackupStore;
use Vortos\Backup\Pitr\PostgresWalArchiver;
use Vortos\Backup\Pitr\PostgresWalFetcher;
use Vortos\Backup\Pitr\ArchivedWalNotFoundException;
use Vortos\Backup\Port\BackupStoreRegistry;
use Vortos\Backup\Service\EncryptionSeam\EnvelopeStreamTransformFactory;
use Vortos\Backup\Tests\Support\FakeKeyProvider;
use Vortos\Backup\Tests\Support\FixedClock;
use Vortos\Backup\Tests\Support\InMemoryCatalogRepository;
use Vortos\Backup\Tests\Support\InMemoryObjectStore;

/**
 * WAL segments must be encrypted at rest, and must still replay.
 *
 * They used to ship in plaintext while the base backups they replay onto were envelope-encrypted.
 * That combination protects very little: a WAL stream carries every row change, so an attacker
 * holding the object store can reconstruct the database from segments alone without ever
 * decrypting a base. The encryption was real and the exposure was total.
 *
 * Encrypting them is only half the fix, and the dangerous half on its own. Without a decrypting
 * fetch, recovery would write ciphertext into pg_wal and the mistake would surface as a corrupt
 * database during the one operation that has to work. So archive and fetch are tested as one
 * round trip, on the same bytes.
 */
final class EncryptedWalRoundTripTest extends TestCase
{
    private string $walFile;
    private string $segmentName;
    private string $plaintext;
    private string $restoreDir;

    protected function setUp(): void
    {
        $this->plaintext = random_bytes(40_000); // spans several cipher chunks
        $this->segmentName = '00000001000000000000002A';
        $this->walFile = sys_get_temp_dir() . '/' . $this->segmentName;
        file_put_contents($this->walFile, $this->plaintext);

        $this->restoreDir = sys_get_temp_dir() . '/walrestore-' . bin2hex(random_bytes(4));
        mkdir($this->restoreDir);
    }

    protected function tearDown(): void
    {
        @unlink($this->walFile);
        foreach (glob($this->restoreDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->restoreDir);
    }

    private function stores(InMemoryObjectStore $object): BackupStoreRegistry
    {
        return new BackupStoreRegistry(new ServiceLocator([
            'object-store' => fn () => new ObjectStoreBackupStore($object),
        ]));
    }

    private function encryptingArchiver(InMemoryObjectStore $object, InMemoryCatalogRepository $catalog, FakeKeyProvider $keys): PostgresWalArchiver
    {
        $cipher = new EnvelopeStreamCipher();

        return new PostgresWalArchiver(
            $this->stores($object),
            $catalog,
            new FixedClock(new DateTimeImmutable('now')),
            'object-store',
            'backups',
            new EnvelopeStreamTransformFactory($keys, $cipher, 'fake-age'),
            $cipher,
            $keys,
        );
    }

    private function fetcher(InMemoryObjectStore $object, ?FakeKeyProvider $keys): PostgresWalFetcher
    {
        return new PostgresWalFetcher(
            $this->stores($object),
            'object-store',
            'backups',
            $keys !== null ? new EnvelopeStreamCipher() : null,
            $keys,
        );
    }

    public function test_an_archived_segment_is_ciphertext_at_rest(): void
    {
        $object = new InMemoryObjectStore();
        $keys = new FakeKeyProvider();

        $this->encryptingArchiver($object, new InMemoryCatalogRepository(), $keys)
            ->archive($this->walFile, 'prod');

        $stored = (string) reset($object->objects);

        self::assertStringStartsWith(EnvelopeHeader::MAGIC, $stored, 'the stored segment is not an envelope');
        self::assertStringNotContainsString(
            substr($this->plaintext, 0, 256),
            $stored,
            'plaintext WAL content is readable in the object store',
        );
    }

    public function test_the_catalog_records_that_the_segment_is_encrypted(): void
    {
        $object = new InMemoryObjectStore();
        $catalog = new InMemoryCatalogRepository();

        $artifact = $this->encryptingArchiver($object, $catalog, new FakeKeyProvider())
            ->archive($this->walFile, 'prod');

        self::assertNotNull(
            $artifact->encryption,
            'without encryption metadata the restore path cannot know to decrypt this segment',
        );
    }

    public function test_a_fetched_segment_is_byte_identical_to_what_postgres_archived(): void
    {
        $object = new InMemoryObjectStore();
        $keys = new FakeKeyProvider();

        $this->encryptingArchiver($object, new InMemoryCatalogRepository(), $keys)
            ->archive($this->walFile, 'prod');

        $destination = $this->restoreDir . '/' . $this->segmentName;
        $written = $this->fetcher($object, $keys)->fetch($this->segmentName, $destination, 'prod');

        self::assertFileExists($destination);
        self::assertSame($this->plaintext, (string) file_get_contents($destination), 'replayed WAL differs from the original');
        self::assertSame(\strlen($this->plaintext), $written);
    }

    /**
     * The envelope uses a fresh nonce per run, so the same segment encrypted twice is different
     * bytes. Comparing stored bytes would report every retry as a conflicting payload and fail the
     * archive_command — and a failing archive_command makes Postgres retry that segment forever,
     * eventually filling pg_wal and stopping writes. Identity is defined on CONTENT.
     */
    public function test_re_archiving_the_same_segment_stays_idempotent_under_encryption(): void
    {
        $object = new InMemoryObjectStore();
        $keys = new FakeKeyProvider();
        $archiver = $this->encryptingArchiver($object, new InMemoryCatalogRepository(), $keys);

        $archiver->archive($this->walFile, 'prod');
        $count = \count($object->objects);

        $archiver->archive($this->walFile, 'prod'); // must not throw

        self::assertCount($count, $object->objects);
    }

    /** Overwriting an archived segment with different content must still be refused. */
    public function test_a_different_payload_under_the_same_segment_name_is_still_refused(): void
    {
        $object = new InMemoryObjectStore();
        $keys = new FakeKeyProvider();
        $archiver = $this->encryptingArchiver($object, new InMemoryCatalogRepository(), $keys);

        $archiver->archive($this->walFile, 'prod');

        file_put_contents($this->walFile, random_bytes(40_000));

        $this->expectException(BackupException::class);
        $archiver->archive($this->walFile, 'prod');
    }

    /**
     * Switching encryption on cannot make already-archived plaintext segments unreadable — a
     * recovery spanning the changeover has to replay both sides of it.
     */
    public function test_plaintext_segments_archived_before_the_change_still_restore(): void
    {
        $object = new InMemoryObjectStore();

        // No transform factory → the pre-encryption archiver.
        $plainArchiver = new PostgresWalArchiver(
            $this->stores($object),
            new InMemoryCatalogRepository(),
            new FixedClock(new DateTimeImmutable('now')),
            'object-store',
            'backups',
        );
        $plainArchiver->archive($this->walFile, 'prod');

        $destination = $this->restoreDir . '/' . $this->segmentName;
        $this->fetcher($object, new FakeKeyProvider())->fetch($this->segmentName, $destination, 'prod');

        self::assertSame($this->plaintext, (string) file_get_contents($destination));
    }

    /**
     * Writing ciphertext into pg_wal would have Postgres accept the file and then report a damaged
     * archive, sending the operator after the wrong problem mid-recovery. Refuse instead.
     */
    public function test_fetching_an_encrypted_segment_without_a_key_refuses_rather_than_writing_ciphertext(): void
    {
        $object = new InMemoryObjectStore();

        $this->encryptingArchiver($object, new InMemoryCatalogRepository(), new FakeKeyProvider())
            ->archive($this->walFile, 'prod');

        $destination = $this->restoreDir . '/' . $this->segmentName;

        try {
            $this->fetcher($object, null)->fetch($this->segmentName, $destination, 'prod');
            self::fail('fetching an encrypted segment without a key provider must fail');
        } catch (BackupException $e) {
            self::assertStringContainsString('refusing to restore ciphertext', $e->getMessage());
        }

        self::assertFileDoesNotExist($destination, 'a partial or ciphertext file was left where Postgres would replay it');
    }

    /**
     * Postgres ends recovery by asking for a segment that does not exist and being told no. That
     * outcome must be distinguishable from a real failure, or every completed recovery looks broken
     * and every broken one looks complete.
     */
    public function test_a_missing_segment_is_reported_as_end_of_archive(): void
    {
        $this->expectException(ArchivedWalNotFoundException::class);

        $this->fetcher(new InMemoryObjectStore(), new FakeKeyProvider())
            ->fetch('000000010000000000000099', $this->restoreDir . '/nope', 'prod');
    }
}
