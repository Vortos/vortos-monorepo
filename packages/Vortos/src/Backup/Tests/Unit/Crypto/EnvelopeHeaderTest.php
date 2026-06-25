<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\Crypto;

use PHPUnit\Framework\TestCase;
use Vortos\Backup\Crypto\EnvelopeFormatException;
use Vortos\Backup\Crypto\EnvelopeHeader;
use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Domain\CompressionCodec;
use Vortos\Backup\Domain\DatabaseEngine;

final class EnvelopeHeaderTest extends TestCase
{
    private function validHeader(): EnvelopeHeader
    {
        return EnvelopeHeader::forEncryption(
            'age',
            'default',
            str_repeat('W', 64),
            CompressionCodec::None,
            DatabaseEngine::Postgres,
            BackupKind::LogicalFull,
            random_bytes(EnvelopeHeader::SECRETSTREAM_HEADER_BYTES),
        );
    }

    public function test_encode_decode_roundtrip(): void
    {
        $original = $this->validHeader();
        $encoded = $original->encode();
        $decoded = EnvelopeHeader::decode($encoded);

        $this->assertSame($original->flags, $decoded->flags);
        $this->assertSame($original->aeadId, $decoded->aeadId);
        $this->assertSame($original->provider, $decoded->provider);
        $this->assertSame($original->recipientId, $decoded->recipientId);
        $this->assertSame($original->wrappedDek, $decoded->wrappedDek);
        $this->assertSame($original->innerCodec, $decoded->innerCodec);
        $this->assertSame($original->engineId, $decoded->engineId);
        $this->assertSame($original->kindId, $decoded->kindId);
        $this->assertSame($original->secretstreamHeader, $decoded->secretstreamHeader);
    }

    public function test_encode_starts_with_magic(): void
    {
        $this->assertTrue(str_starts_with($this->validHeader()->encode(), EnvelopeHeader::MAGIC));
    }

    public function test_aad_excludes_ciphertext_but_includes_fixed_prefix(): void
    {
        $h = $this->validHeader();
        $aad = $h->aad();

        $this->assertTrue(str_starts_with($aad, EnvelopeHeader::MAGIC));
        $this->assertStringContainsString($h->wrappedDek, $aad);
    }

    public function test_decode_rejects_bad_magic(): void
    {
        $this->expectException(EnvelopeFormatException::class);
        $this->expectExceptionMessageMatches('/magic/i');

        EnvelopeHeader::decode("BADMG\x00" . str_repeat("\x00", 200));
    }

    public function test_decode_rejects_unknown_aead_id(): void
    {
        $encoded = $this->validHeader()->encode();
        // aead_id is at offset 7 (after magic(6) + flags(1))
        $tampered = substr($encoded, 0, 7) . "\xFF" . substr($encoded, 8);

        $this->expectException(EnvelopeFormatException::class);
        $this->expectExceptionMessageMatches('/AEAD/i');

        EnvelopeHeader::decode($tampered);
    }

    public function test_decode_rejects_short_buffer(): void
    {
        $this->expectException(EnvelopeFormatException::class);
        $this->expectExceptionMessageMatches('/truncat/i');

        EnvelopeHeader::decode("VBKP");
    }

    public function test_decode_rejects_truncated_length_prefix(): void
    {
        $this->expectException(EnvelopeFormatException::class);

        // magic + flags + aead_id + 2 bytes of a 4-byte length
        EnvelopeHeader::decode("VBKP1\x00\x00\x01\x00\x00");
    }

    public function test_decode_rejects_zero_length_wrapped_dek(): void
    {
        $this->expectException(EnvelopeFormatException::class);
        $this->expectExceptionMessageMatches('/empty/i');

        $buf = "VBKP1\x00";
        $buf .= "\x00"; // flags
        $buf .= "\x01"; // aead_id
        $buf .= pack('N', 3) . 'age'; // provider
        $buf .= pack('N', 7) . 'default'; // recipientId
        $buf .= pack('N', 0); // wrappedDek = 0 length → empty

        EnvelopeHeader::decode($buf);
    }

    public function test_constructor_rejects_empty_provider(): void
    {
        $this->expectException(EnvelopeFormatException::class);

        new EnvelopeHeader(0, 0x01, '', 'r', 'w', 0, 1, 1, random_bytes(EnvelopeHeader::SECRETSTREAM_HEADER_BYTES));
    }

    public function test_constructor_rejects_empty_recipient(): void
    {
        $this->expectException(EnvelopeFormatException::class);

        new EnvelopeHeader(0, 0x01, 'age', '', 'w', 0, 1, 1, random_bytes(EnvelopeHeader::SECRETSTREAM_HEADER_BYTES));
    }

    public function test_constructor_rejects_empty_wrapped_dek(): void
    {
        $this->expectException(EnvelopeFormatException::class);

        new EnvelopeHeader(0, 0x01, 'age', 'r', '', 0, 1, 1, random_bytes(EnvelopeHeader::SECRETSTREAM_HEADER_BYTES));
    }

    public function test_constructor_rejects_bad_secretstream_header_length(): void
    {
        $this->expectException(EnvelopeFormatException::class);

        new EnvelopeHeader(0, 0x01, 'age', 'r', 'w', 0, 1, 1, 'short');
    }

    public function test_codec_engine_kind_accessors(): void
    {
        $h = EnvelopeHeader::forEncryption(
            'age',
            'default',
            'wrapped',
            CompressionCodec::Gzip,
            DatabaseEngine::Mongo,
            BackupKind::MongoArchive,
            random_bytes(EnvelopeHeader::SECRETSTREAM_HEADER_BYTES),
        );

        $this->assertSame(CompressionCodec::Gzip, $h->codec());
        $this->assertSame(DatabaseEngine::Mongo, $h->engine());
        $this->assertSame(BackupKind::MongoArchive, $h->kind());
    }

    public function test_compressed_before_encrypt_flag(): void
    {
        $h = EnvelopeHeader::forEncryption(
            'age',
            'default',
            'wrapped',
            CompressionCodec::None,
            DatabaseEngine::Postgres,
            BackupKind::LogicalFull,
            random_bytes(EnvelopeHeader::SECRETSTREAM_HEADER_BYTES),
            compressedBeforeEncrypt: true,
        );

        $this->assertSame(0x01, $h->flags);
        $decoded = EnvelopeHeader::decode($h->encode());
        $this->assertSame(0x01, $decoded->flags);
    }

    public function test_all_engine_kind_codec_roundtrip(): void
    {
        foreach (DatabaseEngine::cases() as $engine) {
            foreach (BackupKind::cases() as $kind) {
                foreach (CompressionCodec::cases() as $codec) {
                    $h = EnvelopeHeader::forEncryption(
                        'age',
                        'default',
                        'wrapped-dek',
                        $codec,
                        $engine,
                        $kind,
                        random_bytes(EnvelopeHeader::SECRETSTREAM_HEADER_BYTES),
                    );
                    $decoded = EnvelopeHeader::decode($h->encode());
                    $this->assertSame($engine, $decoded->engine());
                    $this->assertSame($kind, $decoded->kind());
                    $this->assertSame($codec, $decoded->codec());
                }
            }
        }
    }
}
