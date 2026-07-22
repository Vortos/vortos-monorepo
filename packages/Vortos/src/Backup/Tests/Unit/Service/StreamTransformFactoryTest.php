<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Vortos\Backup\Crypto\EnvelopeStreamCipher;
use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Domain\CompressionCodec;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Service\EncryptionSeam\EnvelopeStreamTransform;
use Vortos\Backup\Service\EncryptionSeam\EnvelopeStreamTransformFactory;
use Vortos\Backup\Service\EncryptionSeam\IdentityStreamTransform;
use Vortos\Backup\Service\EncryptionSeam\IdentityStreamTransformFactory;
use Vortos\Backup\Tests\Support\FakeKeyProvider;

/**
 * The seam that was documented as "encryption-ready" while being unreachable: EnvelopeStreamTransform
 * needs per-backup engine/kind/codec, so it could not be a shared service and was therefore never
 * registered at all. These tests pin the factory contract that makes it selectable.
 */
final class StreamTransformFactoryTest extends TestCase
{
    public function test_identity_factory_returns_a_no_op_transform(): void
    {
        $factory = new IdentityStreamTransformFactory(new IdentityStreamTransform());

        $transform = $factory->forBackup(DatabaseEngine::Postgres, BackupKind::LogicalFull, CompressionCodec::None);

        $this->assertInstanceOf(IdentityStreamTransform::class, $transform);
        $this->assertSame('identity', $transform->name());
    }

    public function test_envelope_factory_produces_a_real_encrypting_transform(): void
    {
        $factory = new EnvelopeStreamTransformFactory(new FakeKeyProvider(), new EnvelopeStreamCipher());

        $transform = $factory->forBackup(DatabaseEngine::Postgres, BackupKind::LogicalFull, CompressionCodec::None);

        $this->assertInstanceOf(EnvelopeStreamTransform::class, $transform);
        $this->assertSame('age-envelope', $transform->name());
    }

    /**
     * A fresh instance per backup is a correctness requirement, not tidiness: the transform holds the
     * envelope's `lastMetadata()` for the runner to catalog, so a shared instance would let two
     * concurrent backups overwrite each other's recipient — surfacing only when a restore failed to
     * decrypt.
     */
    public function test_envelope_factory_returns_a_fresh_instance_per_backup(): void
    {
        $factory = new EnvelopeStreamTransformFactory(new FakeKeyProvider(), new EnvelopeStreamCipher());

        $first = $factory->forBackup(DatabaseEngine::Postgres, BackupKind::LogicalFull, CompressionCodec::None);
        $second = $factory->forBackup(DatabaseEngine::Postgres, BackupKind::LogicalFull, CompressionCodec::None);

        $this->assertNotSame($first, $second);
    }
}
