<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\Restore;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Vortos\Backup\Crypto\EnvelopeStreamCipher;
use Vortos\Backup\Domain\BackupArtifact;
use Vortos\Backup\Domain\BackupChecksum;
use Vortos\Backup\Domain\BackupId;
use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Domain\CompressionCodec;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Domain\EncryptionMetadata;
use Vortos\Backup\Domain\Exception\IntegrityException;
use Vortos\Backup\Domain\SourceRef;
use Vortos\Backup\Driver\ObjectStore\ObjectStoreBackupStore;
use Vortos\Backup\Restore\RestoreCoordinator;
use Vortos\Backup\Restore\RestoreRequest;
use Vortos\Backup\Restore\RestoreTargetRegistry;
use Vortos\Backup\Service\EncryptionSeam\EnvelopeStreamTransform;
use Vortos\Backup\Tests\Support\FakeKeyProvider;
use Vortos\Backup\Tests\Support\FakeRestoreTarget;
use Vortos\Backup\Tests\Support\InMemoryObjectStore;

final class RestoreCoordinatorTest extends TestCase
{
    public function test_restore_unencrypted_artifact(): void
    {
        $objectStore = new InMemoryObjectStore();
        $plaintext = "PGDMP\x00test-data";
        $objectStore->objects['backups/test'] = $plaintext;

        $target = new FakeRestoreTarget();
        $targets = new RestoreTargetRegistry(new ServiceLocator(['postgres' => fn () => $target]));
        $coordinator = new RestoreCoordinator($targets, new EnvelopeStreamCipher(), null);

        $artifact = new BackupArtifact(
            BackupId::generate(DatabaseEngine::Postgres, BackupKind::LogicalFull, new DateTimeImmutable()),
            DatabaseEngine::Postgres, BackupKind::LogicalFull, 'test',
            new DateTimeImmutable(), strlen($plaintext),
            BackupChecksum::ofString($plaintext), 'backups/test',
            CompressionCodec::None, SourceRef::none(),
        );

        $store = new ObjectStoreBackupStore($objectStore);
        $coordinator->restore($artifact, $store, new RestoreRequest('pgsql://test@localhost/test'));

        $this->assertSame($plaintext, $target->restoredData);
    }

    public function test_restore_encrypted_artifact(): void
    {
        $keyProvider = new FakeKeyProvider();
        $cipher = new EnvelopeStreamCipher();
        $objectStore = new InMemoryObjectStore();

        $plaintext = "PGDMP\x00encrypted-data";
        $dek = sodium_crypto_secretstream_xchacha20poly1305_keygen();
        $wrapped = $keyProvider->wrap(\Vortos\Secrets\Key\DataKey::fromRaw($dek));

        [$encrypted] = $cipher->encrypt(
            $this->stream($plaintext), $dek,
            'fake-age', $wrapped->recipientId, $wrapped->ciphertext,
            CompressionCodec::None, DatabaseEngine::Postgres, BackupKind::LogicalFull,
        );
        $encData = stream_get_contents($encrypted);
        $objectStore->objects['backups/enc-test'] = $encData;

        $target = new FakeRestoreTarget();
        $targets = new RestoreTargetRegistry(new ServiceLocator(['postgres' => fn () => $target]));
        $coordinator = new RestoreCoordinator($targets, $cipher, $keyProvider);

        $artifact = new BackupArtifact(
            BackupId::generate(DatabaseEngine::Postgres, BackupKind::LogicalFull, new DateTimeImmutable()),
            DatabaseEngine::Postgres, BackupKind::LogicalFull, 'test',
            new DateTimeImmutable(), strlen($encData),
            BackupChecksum::ofString($encData), 'backups/enc-test',
            CompressionCodec::None, SourceRef::none(),
            null, null,
            new EncryptionMetadata('fake-age', $wrapped->recipientId, 0x01),
        );

        $store = new ObjectStoreBackupStore($objectStore);
        $coordinator->restore($artifact, $store, new RestoreRequest('pgsql://test@localhost/test'));

        $this->assertSame($plaintext, $target->restoredData);
    }

    public function test_encrypted_artifact_without_key_provider_raises(): void
    {
        $objectStore = new InMemoryObjectStore();
        $objectStore->objects['backups/no-key'] = 'some data';

        $target = new FakeRestoreTarget();
        $targets = new RestoreTargetRegistry(new ServiceLocator(['postgres' => fn () => $target]));
        $coordinator = new RestoreCoordinator($targets, new EnvelopeStreamCipher(), null);

        $artifact = new BackupArtifact(
            BackupId::generate(DatabaseEngine::Postgres, BackupKind::LogicalFull, new DateTimeImmutable()),
            DatabaseEngine::Postgres, BackupKind::LogicalFull, 'test',
            new DateTimeImmutable(), 9, BackupChecksum::ofString('some data'),
            'backups/no-key', CompressionCodec::None, SourceRef::none(),
            null, null,
            new EncryptionMetadata('age', 'default', 0x01),
        );

        $this->expectException(IntegrityException::class);
        $this->expectExceptionMessageMatches('/no key provider/');

        $store = new ObjectStoreBackupStore($objectStore);
        $coordinator->restore($artifact, $store, new RestoreRequest('pgsql://test@localhost/test'));
    }

    public function test_restore_request_rejects_empty_dsn(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RestoreRequest('');
    }

    /** @return resource */
    private function stream(string $data): mixed
    {
        $s = fopen('php://temp', 'r+b');
        fwrite($s, $data);
        rewind($s);

        return $s;
    }
}
