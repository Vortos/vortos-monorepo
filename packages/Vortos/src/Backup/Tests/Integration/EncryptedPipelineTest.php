<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Integration;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Vortos\Backup\Crypto\EnvelopeHeader;
use Vortos\Backup\Crypto\EnvelopeStreamCipher;
use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Domain\BackupRequest;
use Vortos\Backup\Domain\CompressionCodec;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Domain\Exception\IntegrityException;
use Vortos\Backup\Driver\ObjectStore\ObjectStoreBackupStore;
use Vortos\Backup\Event\BackupEvent;
use Vortos\Backup\Port\BackupStoreRegistry;
use Vortos\Backup\Port\BackupTargetRegistry;
use Vortos\Backup\Restore\RestoreCoordinator;
use Vortos\Backup\Restore\RestoreTargetRegistry;
use Vortos\Backup\Service\BackupLock;
use Vortos\Backup\Service\BackupRunner;
use Vortos\Backup\Service\EncryptionSeam\EnvelopeStreamTransform;
use Vortos\Backup\Service\IntegrityVerifier;
use Vortos\Backup\Tests\Support\CollectingEventSink;
use Vortos\Backup\Tests\Support\FakeBackupTarget;
use Vortos\Backup\Tests\Support\FakeKeyProvider;
use Vortos\Backup\Tests\Support\FixedClock;
use Vortos\Backup\Tests\Support\InMemoryCatalogRepository;
use Vortos\Backup\Tests\Support\InMemoryObjectStore;

final class EncryptedPipelineTest extends TestCase
{
    private InMemoryObjectStore $objectStore;
    private InMemoryCatalogRepository $catalog;
    private CollectingEventSink $events;
    private FakeKeyProvider $keyProvider;

    protected function setUp(): void
    {
        $this->objectStore = new InMemoryObjectStore();
        $this->catalog = new InMemoryCatalogRepository();
        $this->events = new CollectingEventSink();
        $this->keyProvider = new FakeKeyProvider();
    }

    private function runner(): BackupRunner
    {
        $target = new FakeBackupTarget();
        $transform = new EnvelopeStreamTransform(
            $this->keyProvider,
            new EnvelopeStreamCipher(),
            DatabaseEngine::Postgres,
            BackupKind::LogicalFull,
            CompressionCodec::None,
        );

        $targets = new BackupTargetRegistry(new ServiceLocator(['postgres' => fn () => $target]));
        $stores = new BackupStoreRegistry(new ServiceLocator(['object-store' => fn () => new ObjectStoreBackupStore($this->objectStore)]));

        return new BackupRunner(
            $targets,
            $stores,
            $this->catalog,
            new IntegrityVerifier(),
            $this->events,
            new \Vortos\Backup\Tests\Support\FixedStreamTransformFactory($transform),
            new BackupLock(sys_get_temp_dir() . '/vortos-enc-test-' . bin2hex(random_bytes(4))),
            new FixedClock(new DateTimeImmutable('2026-06-24 02:00:00')),
            'object-store',
            'backups',
        );
    }

    public function test_encrypted_run_stores_valid_envelope(): void
    {
        $artifact = $this->runner()->run(
            new BackupRequest(DatabaseEngine::Postgres, BackupKind::LogicalFull, 'prod'),
        );

        $this->assertNotNull($artifact);
        $this->assertCount(1, $this->objectStore->objects);

        $stored = array_values($this->objectStore->objects)[0];
        $this->assertTrue(str_starts_with($stored, EnvelopeHeader::MAGIC), 'Stored object must be an envelope, not plaintext.');
        $this->assertStringNotContainsString('PGDMP', $stored, 'Plaintext PGDMP must not appear in ciphertext.');
    }

    public function test_encrypted_run_succeeds_with_envelope_aware_verify(): void
    {
        $artifact = $this->runner()->run(
            new BackupRequest(DatabaseEngine::Postgres, BackupKind::LogicalFull, 'prod'),
        );

        $this->assertNotNull($artifact);
        $this->assertContains(BackupEvent::TYPE_SUCCEEDED, $this->events->types());
        $this->assertArrayHasKey($artifact->id->value(), $this->catalog->rows);
    }

    public function test_encrypted_backup_can_be_decrypted_back(): void
    {
        $artifact = $this->runner()->run(
            new BackupRequest(DatabaseEngine::Postgres, BackupKind::LogicalFull, 'prod'),
        );

        $this->assertNotNull($artifact);

        $store = new ObjectStoreBackupStore($this->objectStore);
        $encrypted = $store->open($artifact->storeKey);
        $cipher = new EnvelopeStreamCipher();
        $decrypted = $cipher->decryptStream($encrypted, fn ($w) => $this->keyProvider->unwrap($w));

        $plaintext = stream_get_contents($decrypted);
        $this->assertSame("PGDMP\x00fake-dump-body", $plaintext);
    }

    public function test_tampered_ciphertext_raises_critical(): void
    {
        $artifact = $this->runner()->run(
            new BackupRequest(DatabaseEngine::Postgres, BackupKind::LogicalFull, 'prod'),
        );
        $this->assertNotNull($artifact);

        $key = $artifact->storeKey;
        $raw = $this->objectStore->objects[$key];
        $raw[strlen($raw) - 5] = chr(ord($raw[strlen($raw) - 5]) ^ 0xFF);
        $this->objectStore->objects[$key] = $raw;

        $store = new ObjectStoreBackupStore($this->objectStore);
        $cipher = new EnvelopeStreamCipher();

        $this->expectException(IntegrityException::class);
        $this->expectExceptionMessageMatches('/auth/');

        $encrypted = $store->open($key);
        $cipher->decryptStream($encrypted, fn ($w) => $this->keyProvider->unwrap($w));
    }

    public function test_truncated_envelope_raises_critical(): void
    {
        $artifact = $this->runner()->run(
            new BackupRequest(DatabaseEngine::Postgres, BackupKind::LogicalFull, 'prod'),
        );
        $this->assertNotNull($artifact);

        $key = $artifact->storeKey;
        $raw = $this->objectStore->objects[$key];
        $this->objectStore->objects[$key] = substr($raw, 0, (int) (strlen($raw) * 0.6));

        $store = new ObjectStoreBackupStore($this->objectStore);
        $cipher = new EnvelopeStreamCipher();

        $this->expectException(IntegrityException::class);
        $this->expectExceptionMessageMatches('/truncat|auth/');

        $encrypted = $store->open($key);
        $cipher->decryptStream($encrypted, fn ($w) => $this->keyProvider->unwrap($w));
    }

    public function test_missing_kek_identity_raises_undecryptable(): void
    {
        $artifact = $this->runner()->run(
            new BackupRequest(DatabaseEngine::Postgres, BackupKind::LogicalFull, 'prod'),
        );
        $this->assertNotNull($artifact);

        $this->keyProvider->disableUnwrap();

        $store = new ObjectStoreBackupStore($this->objectStore);
        $cipher = new EnvelopeStreamCipher();

        $this->expectException(\Vortos\Secrets\Exception\KeyUnavailableException::class);

        $encrypted = $store->open($artifact->storeKey);
        $cipher->decryptStream($encrypted, fn ($w) => $this->keyProvider->unwrap($w));
    }

    public function test_integrity_verifier_envelope_aware_check(): void
    {
        $artifact = $this->runner()->run(
            new BackupRequest(DatabaseEngine::Postgres, BackupKind::LogicalFull, 'prod'),
        );
        $this->assertNotNull($artifact);

        $store = new ObjectStoreBackupStore($this->objectStore);
        $verifier = new IntegrityVerifier();

        $verifier->verify(
            $store,
            $artifact->storeKey,
            $artifact->checksum,
            $artifact->engine,
            $artifact->kind,
            $artifact->codec,
            new \Vortos\Backup\Domain\EncryptionMetadata('fake-age', 'fake-recipient', 0x01),
        );
        $this->assertTrue(true, 'Envelope-aware verify should pass on a valid encrypted artifact.');
    }

    public function test_integrity_verifier_rejects_corrupted_envelope_header(): void
    {
        $artifact = $this->runner()->run(
            new BackupRequest(DatabaseEngine::Postgres, BackupKind::LogicalFull, 'prod'),
        );
        $this->assertNotNull($artifact);

        $key = $artifact->storeKey;
        $raw = $this->objectStore->objects[$key];
        // Corrupt the magic
        $this->objectStore->objects[$key] = 'BADMG!' . substr($raw, 6);

        $store = new ObjectStoreBackupStore($this->objectStore);
        $verifier = new IntegrityVerifier();

        $this->expectException(IntegrityException::class);
        $this->expectExceptionMessageMatches('/magic|envelope/i');

        $verifier->verify(
            $store,
            $artifact->storeKey,
            $artifact->checksum,
            $artifact->engine,
            $artifact->kind,
            $artifact->codec,
            new \Vortos\Backup\Domain\EncryptionMetadata('fake-age', 'fake-recipient', 0x01),
        );
    }
}
