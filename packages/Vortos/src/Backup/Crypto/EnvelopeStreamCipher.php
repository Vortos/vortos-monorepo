<?php

declare(strict_types=1);

namespace Vortos\Backup\Crypto;

use RuntimeException;
use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Domain\CompressionCodec;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Domain\Exception\IntegrityException;
use Vortos\Secrets\Key\DataKey;
use Vortos\Secrets\Key\WrappedKey;

/**
 * Streaming envelope encryption/decryption using libsodium secretstream
 * (XChaCha20-Poly1305). Bounded memory: one chunk buffered at a time.
 *
 * Encrypt: returns a readable resource that emits header ∥ ciphertext chunks.
 * Decrypt: reads envelope header, unwraps DEK via the provided callable,
 * streams plaintext; truncation (missing TAG_FINAL) and auth failures fail closed.
 */
final class EnvelopeStreamCipher
{
    public const CHUNK_SIZE = 65536;
    private const MAX_HEADER_SIZE = 65536;
    private const MAX_FIELD_SIZE = 16384;

    /**
     * Encrypt a plaintext stream, producing the full envelope (header + ciphertext).
     *
     * @param resource $plaintext
     * @return array{0: mixed, 1: EnvelopeHeader} [encrypted resource, header]
     */
    public function encrypt(
        mixed $plaintext,
        string $dek,
        string $provider,
        string $recipientId,
        string $wrappedDek,
        CompressionCodec $codec,
        DatabaseEngine $engine,
        BackupKind $kind,
        bool $compressedBeforeEncrypt = false,
    ): array {
        [$state, $ssHeader] = sodium_crypto_secretstream_xchacha20poly1305_init_push($dek);

        $header = EnvelopeHeader::forEncryption(
            $provider,
            $recipientId,
            $wrappedDek,
            $codec,
            $engine,
            $kind,
            $ssHeader,
            $compressedBeforeEncrypt,
        );

        $aad = $header->aad();
        $encoded = $header->encode();

        $stream = fopen('php://temp', 'r+b');
        if ($stream === false) {
            throw new RuntimeException('Cannot open temp stream for encryption.');
        }

        fwrite($stream, $encoded);

        $pending = fread($plaintext, self::CHUNK_SIZE);
        if ($pending === false || $pending === '') {
            $pending = null;
        }

        if ($pending === null) {
            $ct = sodium_crypto_secretstream_xchacha20poly1305_push(
                $state,
                '',
                $aad,
                SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL,
            );
            fwrite($stream, $ct);
        } else {
            $isFirst = true;
            while ($pending !== null) {
                $next = fread($plaintext, self::CHUNK_SIZE);
                $isLast = ($next === false || $next === '');
                $tag = $isLast
                    ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
                    : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;

                $ct = sodium_crypto_secretstream_xchacha20poly1305_push(
                    $state,
                    $pending,
                    $isFirst ? $aad : '',
                    $tag,
                );
                fwrite($stream, $ct);
                $isFirst = false;
                $pending = $isLast ? null : $next;
            }
        }

        sodium_memzero($state);
        rewind($stream);

        return [$stream, $header];
    }

    /**
     * Decrypt an envelope stream, unwrapping the DEK via the provided callable.
     *
     * @param resource $envelope
     * @param callable(WrappedKey): DataKey $unwrap
     * @return resource
     */
    public function decryptStream(mixed $envelope, callable $unwrap): mixed
    {
        $headerRaw = $this->readEnvelopeHeaderBytes($envelope);
        $header = EnvelopeHeader::decode($headerRaw);

        $wrappedKey = new WrappedKey($header->wrappedDek, $header->recipientId);
        $dataKey = $unwrap($wrappedKey);
        $dek = $dataKey->revealForEncryption();

        try {
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header->secretstreamHeader, $dek);
        } finally {
            $dataKey->wipe();
        }

        $aad = $header->aad();
        $abytes = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;
        $cipherChunkSize = self::CHUNK_SIZE + $abytes;

        $output = fopen('php://temp', 'r+b');
        if ($output === false) {
            throw new RuntimeException('Cannot open temp stream for decryption.');
        }

        $isFirst = true;
        $sawFinal = false;

        while (!feof($envelope)) {
            $cipherChunk = fread($envelope, $cipherChunkSize);
            if ($cipherChunk === false || $cipherChunk === '') {
                break;
            }

            $result = sodium_crypto_secretstream_xchacha20poly1305_pull(
                $state,
                $cipherChunk,
                $isFirst ? $aad : '',
            );

            if ($result === false) {
                throw IntegrityException::undecryptable('auth');
            }

            [$pt, $tag] = $result;
            fwrite($output, $pt);
            $isFirst = false;

            if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                $sawFinal = true;
                break;
            }
        }

        sodium_memzero($state);

        if (!$sawFinal) {
            throw IntegrityException::undecryptable('truncated');
        }

        rewind($output);

        return $output;
    }

    /**
     * Lazily decrypt an envelope stream, yielding plaintext chunks one at a time.
     * Peak memory: one encrypted chunk + one decrypted chunk (~128KB).
     *
     * @param resource $envelope
     * @param callable(WrappedKey): DataKey $unwrap
     * @return \Generator<int, string, void, void>
     */
    public function decryptStreamLazy(mixed $envelope, callable $unwrap): \Generator
    {
        $headerRaw = $this->readEnvelopeHeaderBytes($envelope);
        $header = EnvelopeHeader::decode($headerRaw);

        $wrappedKey = new WrappedKey($header->wrappedDek, $header->recipientId);
        $dataKey = $unwrap($wrappedKey);
        $dek = $dataKey->revealForEncryption();

        try {
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header->secretstreamHeader, $dek);
        } finally {
            $dataKey->wipe();
        }

        $aad = $header->aad();
        $abytes = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;
        $cipherChunkSize = self::CHUNK_SIZE + $abytes;

        $isFirst = true;
        $sawFinal = false;

        while (!feof($envelope)) {
            $cipherChunk = fread($envelope, $cipherChunkSize);
            if ($cipherChunk === false || $cipherChunk === '') {
                break;
            }

            $result = sodium_crypto_secretstream_xchacha20poly1305_pull(
                $state,
                $cipherChunk,
                $isFirst ? $aad : '',
            );

            if ($result === false) {
                throw IntegrityException::undecryptable('auth');
            }

            [$pt, $tag] = $result;
            $isFirst = false;

            if ($pt !== '') {
                yield $pt;
            }

            if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                $sawFinal = true;
                break;
            }
        }

        sodium_memzero($state);

        if (!$sawFinal) {
            throw IntegrityException::undecryptable('truncated');
        }
    }

    /**
     * Read the full envelope header from a stream, leaving the stream positioned
     * at the first ciphertext byte. Reads incrementally — no fixed-size buffer.
     */
    private function readEnvelopeHeaderBytes(mixed $envelope): string
    {
        $preambleSize = 8; // magic(6) + flags(1) + aead_id(1)
        $preamble = $this->readExact($envelope, $preambleSize);

        if (strlen($preamble) < 6) {
            throw IntegrityException::envelopeMalformed('too short');
        }

        $magic = substr($preamble, 0, 6);
        if ($magic !== EnvelopeHeader::MAGIC) {
            throw IntegrityException::envelopeMalformed('bad magic');
        }

        $buf = $preamble;
        $fieldNames = ['provider', 'recipientId', 'wrappedDek'];

        foreach ($fieldNames as $fieldName) {
            $lenBytes = $this->readExact($envelope, 4);
            if (strlen($lenBytes) < 4) {
                throw IntegrityException::envelopeMalformed('header truncated');
            }

            /** @var array{1: int} $u */
            $u = unpack('N', $lenBytes);
            $fieldLen = $u[1];

            if ($fieldLen > self::MAX_FIELD_SIZE) {
                throw EnvelopeFormatException::fieldTooLarge($fieldName, $fieldLen, self::MAX_FIELD_SIZE);
            }

            if (strlen($buf) + 4 + $fieldLen > self::MAX_HEADER_SIZE) {
                throw EnvelopeFormatException::headerTooLarge(strlen($buf) + 4 + $fieldLen, self::MAX_HEADER_SIZE);
            }

            $buf .= $lenBytes;

            if ($fieldLen > 0) {
                $fieldData = $this->readExact($envelope, $fieldLen);
                if (strlen($fieldData) < $fieldLen) {
                    throw IntegrityException::envelopeMalformed('header truncated');
                }
                $buf .= $fieldData;
            }
        }

        $tailSize = 3 + EnvelopeHeader::SECRETSTREAM_HEADER_BYTES; // innerCodec(1) + engineId(1) + kindId(1) + ssHeader
        $tail = $this->readExact($envelope, $tailSize);
        if (strlen($tail) < $tailSize) {
            throw IntegrityException::envelopeMalformed('header truncated');
        }
        $buf .= $tail;

        return $buf;
    }

    private function readExact(mixed $stream, int $bytes): string
    {
        $buf = '';
        $remaining = $bytes;
        while ($remaining > 0 && !feof($stream)) {
            $chunk = fread($stream, $remaining);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $buf .= $chunk;
            $remaining -= strlen($chunk);
        }

        return $buf;
    }
}
