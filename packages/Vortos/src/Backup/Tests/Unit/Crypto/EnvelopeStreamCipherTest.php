<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\Crypto;

use PHPUnit\Framework\TestCase;
use Vortos\Backup\Crypto\EnvelopeStreamCipher;
use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Domain\CompressionCodec;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Domain\Exception\IntegrityException;
use Vortos\Secrets\Key\DataKey;
use Vortos\Secrets\Key\WrappedKey;

final class EnvelopeStreamCipherTest extends TestCase
{
    private EnvelopeStreamCipher $cipher;

    protected function setUp(): void
    {
        $this->cipher = new EnvelopeStreamCipher();
    }

    /** @return resource */
    private function stream(string $data): mixed
    {
        $s = fopen('php://temp', 'r+b');
        fwrite($s, $data);
        rewind($s);

        return $s;
    }

    /**
     * @return array{0: mixed, 1: string, 2: string} [encrypted resource, dek, wrappedDek]
     */
    private function encryptPayload(string $plaintext): array
    {
        $dek = sodium_crypto_secretstream_xchacha20poly1305_keygen();
        $wrappedDek = 'test-wrapped-' . bin2hex(random_bytes(8));

        [$encrypted] = $this->cipher->encrypt(
            $this->stream($plaintext),
            $dek,
            'age',
            'default',
            $wrappedDek,
            CompressionCodec::None,
            DatabaseEngine::Postgres,
            BackupKind::LogicalFull,
        );

        return [$encrypted, $dek, $wrappedDek];
    }

    private function unwrapFactory(string $dek): callable
    {
        return static fn (WrappedKey $w): DataKey => DataKey::fromRaw($dek);
    }

    /**
     * The bug that made every encrypted production backup permanently undecryptable.
     *
     * The write path sealed whatever fread() returned, so a short-reading source produced
     * VARIABLE-sized chunks, while the read path reads a FIXED CHUNK_SIZE + abytes per chunk. The
     * framing desynchronised at the first short read and every chunk after it failed authentication
     * — reported as "Backup undecryptable: auth", indistinguishable from tampering.
     *
     * It survived a full crypto suite because every other test here feeds php://temp, which always
     * returns the full request until EOF. Real sources do not: the plaintext is a pg_dump PIPE and
     * the ciphertext is an object-store DOWNLOAD, and both short-read constantly. Multi-chunk is
     * essential — a payload under one chunk cannot desynchronise.
     */
    public function test_roundtrip_when_the_plaintext_source_short_reads(): void
    {
        $payload = str_repeat('A', EnvelopeStreamCipher::CHUNK_SIZE * 4 + 517);

        $source = fopen(ShortReadStreamWrapper::urlFor($payload), 'rb');
        self::assertIsResource($source);

        $dek = sodium_crypto_secretstream_xchacha20poly1305_keygen();
        [$encrypted] = $this->cipher->encrypt(
            $source,
            $dek,
            'age',
            'default',
            'test-wrapped',
            CompressionCodec::None,
            DatabaseEngine::Postgres,
            BackupKind::LogicalFull,
        );

        $decrypted = $this->cipher->decryptStream($encrypted, $this->unwrapFactory($dek));

        $this->assertSame($payload, stream_get_contents($decrypted));
    }

    /** The same hazard on the read path: an object-store download short-reads too. */
    public function test_roundtrip_when_the_envelope_source_short_reads(): void
    {
        $payload = str_repeat('B', EnvelopeStreamCipher::CHUNK_SIZE * 3 + 91);

        [$encrypted, $dek] = $this->encryptPayload($payload);
        $envelopeBytes = stream_get_contents($encrypted);

        $shortReading = fopen(ShortReadStreamWrapper::urlFor($envelopeBytes), 'rb');
        self::assertIsResource($shortReading);

        $decrypted = $this->cipher->decryptStream($shortReading, $this->unwrapFactory($dek));

        $this->assertSame($payload, stream_get_contents($decrypted));
    }

    public function test_encrypt_decrypt_roundtrip_empty(): void
    {
        [$encrypted, $dek] = $this->encryptPayload('');
        $decrypted = $this->cipher->decryptStream($encrypted, $this->unwrapFactory($dek));
        $this->assertSame('', stream_get_contents($decrypted));
    }

    public function test_encrypt_decrypt_roundtrip_one_byte(): void
    {
        [$encrypted, $dek] = $this->encryptPayload('X');
        $decrypted = $this->cipher->decryptStream($encrypted, $this->unwrapFactory($dek));
        $this->assertSame('X', stream_get_contents($decrypted));
    }

    public function test_encrypt_decrypt_roundtrip_exactly_one_chunk(): void
    {
        $data = str_repeat('A', EnvelopeStreamCipher::CHUNK_SIZE);
        [$encrypted, $dek] = $this->encryptPayload($data);
        $decrypted = $this->cipher->decryptStream($encrypted, $this->unwrapFactory($dek));
        $this->assertSame($data, stream_get_contents($decrypted));
    }

    public function test_encrypt_decrypt_roundtrip_chunk_minus_one(): void
    {
        $data = str_repeat('B', EnvelopeStreamCipher::CHUNK_SIZE - 1);
        [$encrypted, $dek] = $this->encryptPayload($data);
        $decrypted = $this->cipher->decryptStream($encrypted, $this->unwrapFactory($dek));
        $this->assertSame($data, stream_get_contents($decrypted));
    }

    public function test_encrypt_decrypt_roundtrip_chunk_plus_one(): void
    {
        $data = str_repeat('C', EnvelopeStreamCipher::CHUNK_SIZE + 1);
        [$encrypted, $dek] = $this->encryptPayload($data);
        $decrypted = $this->cipher->decryptStream($encrypted, $this->unwrapFactory($dek));
        $this->assertSame($data, stream_get_contents($decrypted));
    }

    public function test_encrypt_decrypt_roundtrip_multi_chunk(): void
    {
        $data = str_repeat('D', EnvelopeStreamCipher::CHUNK_SIZE * 3 + 42);
        [$encrypted, $dek] = $this->encryptPayload($data);
        $decrypted = $this->cipher->decryptStream($encrypted, $this->unwrapFactory($dek));
        $this->assertSame($data, stream_get_contents($decrypted));
    }

    public function test_ciphertext_differs_from_plaintext(): void
    {
        $data = "PGDMP\x00test-backup-data";
        [$encrypted] = $this->encryptPayload($data);
        $ct = stream_get_contents($encrypted);
        $this->assertStringNotContainsString($data, $ct);
    }

    public function test_wrong_dek_fails_auth(): void
    {
        [$encrypted] = $this->encryptPayload('secret data');
        rewind($encrypted);

        $wrongDek = sodium_crypto_secretstream_xchacha20poly1305_keygen();

        $this->expectException(IntegrityException::class);
        $this->expectExceptionMessageMatches('/auth/');

        $this->cipher->decryptStream($encrypted, $this->unwrapFactory($wrongDek));
    }

    public function test_flipped_ciphertext_byte_fails_auth(): void
    {
        $data = str_repeat('E', 1000);
        [$encrypted, $dek] = $this->encryptPayload($data);
        $raw = stream_get_contents($encrypted);

        // Flip a byte in the ciphertext area (well past the header)
        $pos = strlen($raw) - 10;
        $raw[$pos] = chr(ord($raw[$pos]) ^ 0xFF);

        $tampered = $this->stream($raw);

        $this->expectException(IntegrityException::class);
        $this->expectExceptionMessageMatches('/auth/');

        $this->cipher->decryptStream($tampered, $this->unwrapFactory($dek));
    }

    public function test_truncated_envelope_fails(): void
    {
        $data = str_repeat('F', EnvelopeStreamCipher::CHUNK_SIZE * 2);
        [$encrypted, $dek] = $this->encryptPayload($data);
        $raw = stream_get_contents($encrypted);

        // Truncate: remove the last chunk (which has TAG_FINAL)
        $truncated = $this->stream(substr($raw, 0, (int) (strlen($raw) * 0.6)));

        $this->expectException(IntegrityException::class);
        $this->expectExceptionMessageMatches('/truncat|auth/');

        $this->cipher->decryptStream($truncated, $this->unwrapFactory($dek));
    }

    public function test_reordered_chunks_fail(): void
    {
        $data = str_repeat('G', EnvelopeStreamCipher::CHUNK_SIZE * 3);
        [$encrypted, $dek] = $this->encryptPayload($data);
        $raw = stream_get_contents($encrypted);

        // Decode the header to find where ciphertext starts
        $headerBytes = $this->readHeaderSize($raw);
        $ct = substr($raw, $headerBytes);
        $abytes = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;
        $chunkCtSize = EnvelopeStreamCipher::CHUNK_SIZE + $abytes;

        if (strlen($ct) < $chunkCtSize * 2) {
            $this->markTestSkipped('Not enough chunks to reorder.');
        }

        // Swap chunk 0 and chunk 1
        $chunk0 = substr($ct, 0, $chunkCtSize);
        $chunk1 = substr($ct, $chunkCtSize, $chunkCtSize);
        $rest = substr($ct, $chunkCtSize * 2);
        $reordered = substr($raw, 0, $headerBytes) . $chunk1 . $chunk0 . $rest;

        $this->expectException(IntegrityException::class);
        $this->expectExceptionMessageMatches('/auth/');

        $this->cipher->decryptStream($this->stream($reordered), $this->unwrapFactory($dek));
    }

    public function test_decrypt_malformed_magic_fails(): void
    {
        $this->expectException(IntegrityException::class);
        $this->expectExceptionMessageMatches('/magic/');

        $this->cipher->decryptStream($this->stream('NOT_AN_ENVELOPE'), fn () => DataKey::fromRaw('x'));
    }

    public function test_decrypt_too_short_fails(): void
    {
        $this->expectException(IntegrityException::class);

        $this->cipher->decryptStream($this->stream('VB'), fn () => DataKey::fromRaw('x'));
    }

    public function test_oversized_field_rejected(): void
    {
        $header = "VBKP1\0";
        $header .= pack('C', 0x00); // flags
        $header .= pack('C', 0x01); // aead_id
        $header .= pack('N', 20000); // provider length = 20KB (over 16KB max)

        $this->expectException(\Vortos\Backup\Crypto\EnvelopeFormatException::class);
        $this->expectExceptionMessageMatches('/exceeds maximum size/');

        $this->cipher->decryptStream($this->stream($header . str_repeat('A', 20000)), fn () => DataKey::fromRaw('x'));
    }

    public function test_encrypted_output_starts_with_envelope_magic(): void
    {
        [$encrypted] = $this->encryptPayload('test');
        $head = fread($encrypted, 6);
        $this->assertSame("VBKP1\0", $head);
    }

    public function test_decrypt_lazy_roundtrip(): void
    {
        $data = str_repeat('L', EnvelopeStreamCipher::CHUNK_SIZE * 3 + 42);
        [$encrypted, $dek] = $this->encryptPayload($data);

        $chunks = $this->cipher->decryptStreamLazy($encrypted, $this->unwrapFactory($dek));
        $result = '';
        foreach ($chunks as $chunk) {
            $result .= $chunk;
        }
        $this->assertSame($data, $result);
    }

    public function test_decrypt_lazy_yields_bounded_chunks(): void
    {
        $data = str_repeat('M', EnvelopeStreamCipher::CHUNK_SIZE * 5);
        [$encrypted, $dek] = $this->encryptPayload($data);

        $chunkCount = 0;
        foreach ($this->cipher->decryptStreamLazy($encrypted, $this->unwrapFactory($dek)) as $chunk) {
            $this->assertLessThanOrEqual(EnvelopeStreamCipher::CHUNK_SIZE, strlen($chunk));
            $chunkCount++;
        }
        $this->assertSame(5, $chunkCount);
    }

    public function test_decrypt_lazy_wrong_key_fails(): void
    {
        [$encrypted] = $this->encryptPayload('secret');
        rewind($encrypted);

        $wrongDek = sodium_crypto_secretstream_xchacha20poly1305_keygen();

        $this->expectException(IntegrityException::class);
        $this->expectExceptionMessageMatches('/auth/');

        foreach ($this->cipher->decryptStreamLazy($encrypted, $this->unwrapFactory($wrongDek)) as $_) {}
    }

    public function test_decrypt_lazy_empty_roundtrip(): void
    {
        [$encrypted, $dek] = $this->encryptPayload('');
        $result = '';
        foreach ($this->cipher->decryptStreamLazy($encrypted, $this->unwrapFactory($dek)) as $chunk) {
            $result .= $chunk;
        }
        $this->assertSame('', $result);
    }

    private function readHeaderSize(string $raw): int
    {
        $offset = 8; // magic(6) + flags(1) + aead_id(1)
        for ($i = 0; $i < 3; $i++) {
            /** @var array{1: int} $u */
            $u = unpack('N', substr($raw, $offset, 4));
            $offset += 4 + $u[1];
        }
        $offset += 3; // innerCodec + engineId + kindId
        $offset += SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES;

        return $offset;
    }
}
