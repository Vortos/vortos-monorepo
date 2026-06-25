<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\Crypto;

use PHPUnit\Framework\TestCase;
use Vortos\Backup\Crypto\EnvelopeHeader;
use Vortos\Backup\Crypto\EnvelopeStreamCipher;
use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Domain\CompressionCodec;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Service\EncryptionSeam\EnvelopeStreamTransform;
use Vortos\Backup\Tests\Support\FakeKeyProvider;

final class EnvelopeStreamTransformTest extends TestCase
{
    public function test_transform_produces_encrypted_envelope(): void
    {
        $keyProvider = new FakeKeyProvider();
        $transform = new EnvelopeStreamTransform(
            $keyProvider,
            new EnvelopeStreamCipher(),
            DatabaseEngine::Postgres,
            BackupKind::LogicalFull,
            CompressionCodec::None,
        );

        $plaintext = "PGDMP\x00test-backup-data";
        $source = fopen('php://temp', 'r+b');
        fwrite($source, $plaintext);
        rewind($source);

        $result = $transform->transform($source);
        $this->assertIsResource($result);

        $head = fread($result, 6);
        $this->assertSame(EnvelopeHeader::MAGIC, $head, 'Transform output must start with VBKP1 magic.');
    }

    public function test_name_is_age_envelope(): void
    {
        $transform = new EnvelopeStreamTransform(
            new FakeKeyProvider(),
            new EnvelopeStreamCipher(),
            DatabaseEngine::Postgres,
            BackupKind::LogicalFull,
            CompressionCodec::None,
        );

        $this->assertSame('age-envelope', $transform->name());
    }

    public function test_metadata_is_available_after_transform(): void
    {
        $transform = new EnvelopeStreamTransform(
            new FakeKeyProvider(),
            new EnvelopeStreamCipher(),
            DatabaseEngine::Postgres,
            BackupKind::LogicalFull,
            CompressionCodec::None,
        );

        $this->assertNull($transform->lastMetadata());

        $source = fopen('php://temp', 'r+b');
        fwrite($source, 'data');
        rewind($source);
        $transform->transform($source);

        $meta = $transform->lastMetadata();
        $this->assertNotNull($meta);
        $this->assertSame('age', $meta->provider);
    }

    public function test_bounded_memory_large_stream(): void
    {
        $transform = new EnvelopeStreamTransform(
            new FakeKeyProvider(),
            new EnvelopeStreamCipher(),
            DatabaseEngine::Postgres,
            BackupKind::LogicalFull,
            CompressionCodec::None,
        );

        $large = str_repeat('X', EnvelopeStreamCipher::CHUNK_SIZE * 5);
        $source = fopen('php://temp', 'r+b');
        fwrite($source, $large);
        rewind($source);

        $result = $transform->transform($source);
        $this->assertIsResource($result);

        $output = stream_get_contents($result);
        $this->assertGreaterThan(strlen($large), strlen($output));
        $this->assertStringNotContainsString($large, $output);
    }

    public function test_ciphertext_does_not_contain_plaintext_magic(): void
    {
        $transform = new EnvelopeStreamTransform(
            new FakeKeyProvider(),
            new EnvelopeStreamCipher(),
            DatabaseEngine::Postgres,
            BackupKind::LogicalFull,
            CompressionCodec::None,
        );

        $source = fopen('php://temp', 'r+b');
        fwrite($source, "PGDMP\x00lots-of-data-" . str_repeat('A', 1000));
        rewind($source);

        $result = $transform->transform($source);
        $output = stream_get_contents($result);

        $this->assertTrue(str_starts_with($output, "VBKP1\0"));
        $this->assertStringNotContainsString('PGDMP', $output);
    }
}
