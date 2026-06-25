<?php

declare(strict_types=1);

namespace Vortos\Backup\Crypto;

use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Domain\CompressionCodec;
use Vortos\Backup\Domain\DatabaseEngine;

/**
 * Self-describing, versioned envelope container header (VBKP1). Pure — no I/O.
 *
 * The header is embedded at the start of every encrypted backup artifact and encodes
 * everything needed to decrypt: which key provider, which recipient, the wrapped DEK,
 * and the secretstream init header. The entire fixed prefix is fed as AAD to every
 * AEAD chunk, binding the DEK to this specific artifact (anti-replay).
 */
final readonly class EnvelopeHeader
{
    public const MAGIC = "VBKP1\0";
    public const AEAD_XCHACHA20POLY1305_SECRETSTREAM = 0x01;
    public const SECRETSTREAM_HEADER_BYTES = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES;

    public function __construct(
        public int $flags,
        public int $aeadId,
        public string $provider,
        public string $recipientId,
        public string $wrappedDek,
        public int $innerCodec,
        public int $engineId,
        public int $kindId,
        public string $secretstreamHeader,
    ) {
        if ($provider === '') {
            throw EnvelopeFormatException::emptyField('provider');
        }
        if ($recipientId === '') {
            throw EnvelopeFormatException::emptyField('recipientId');
        }
        if ($wrappedDek === '') {
            throw EnvelopeFormatException::emptyField('wrappedDek');
        }
        if (strlen($secretstreamHeader) !== self::SECRETSTREAM_HEADER_BYTES) {
            throw EnvelopeFormatException::truncated('secretstream header');
        }
    }

    public static function forEncryption(
        string $provider,
        string $recipientId,
        string $wrappedDek,
        CompressionCodec $codec,
        DatabaseEngine $engine,
        BackupKind $kind,
        string $secretstreamHeader,
        bool $compressedBeforeEncrypt = false,
    ): self {
        return new self(
            flags: $compressedBeforeEncrypt ? 0x01 : 0x00,
            aeadId: self::AEAD_XCHACHA20POLY1305_SECRETSTREAM,
            provider: $provider,
            recipientId: $recipientId,
            wrappedDek: $wrappedDek,
            innerCodec: self::codecToInt($codec),
            engineId: self::engineToInt($engine),
            kindId: self::kindToInt($kind),
            secretstreamHeader: $secretstreamHeader,
        );
    }

    public function encode(): string
    {
        $buf = self::MAGIC;
        $buf .= pack('C', $this->flags);
        $buf .= pack('C', $this->aeadId);
        $buf .= self::packLenPrefixed($this->provider);
        $buf .= self::packLenPrefixed($this->recipientId);
        $buf .= self::packLenPrefixed($this->wrappedDek);
        $buf .= pack('C', $this->innerCodec);
        $buf .= pack('CC', $this->engineId, $this->kindId);
        $buf .= $this->secretstreamHeader;

        return $buf;
    }

    public static function decode(string $data): self
    {
        $offset = 0;
        $len = strlen($data);

        if ($len < 6) {
            throw EnvelopeFormatException::truncated('magic');
        }

        $magic = substr($data, $offset, 6);
        if ($magic !== self::MAGIC) {
            throw EnvelopeFormatException::badMagic($magic);
        }
        $offset += 6;

        $flags = self::readByte($data, $offset, $len, 'flags');
        $aeadId = self::readByte($data, $offset, $len, 'aead_id');

        if ($aeadId !== self::AEAD_XCHACHA20POLY1305_SECRETSTREAM) {
            throw EnvelopeFormatException::unknownAeadId($aeadId);
        }

        $provider = self::readLenPrefixed($data, $offset, $len, 'provider');
        $recipientId = self::readLenPrefixed($data, $offset, $len, 'recipientId');
        $wrappedDek = self::readLenPrefixed($data, $offset, $len, 'wrappedDek');

        $innerCodec = self::readByte($data, $offset, $len, 'innerCodec');
        $engineId = self::readByte($data, $offset, $len, 'engineId');
        $kindId = self::readByte($data, $offset, $len, 'kindId');

        $ssHeaderSize = self::SECRETSTREAM_HEADER_BYTES;
        if ($offset + $ssHeaderSize > $len) {
            throw EnvelopeFormatException::truncated('secretstream header');
        }
        $ssHeader = substr($data, $offset, $ssHeaderSize);

        return new self($flags, $aeadId, $provider, $recipientId, $wrappedDek, $innerCodec, $engineId, $kindId, $ssHeader);
    }

    /** The AAD bound to every AEAD chunk — the full encoded header prefix. No secret material. */
    public function aad(): string
    {
        return $this->encode();
    }

    public function headerSize(): int
    {
        return strlen($this->encode());
    }

    public function codec(): CompressionCodec
    {
        return self::intToCodec($this->innerCodec);
    }

    public function engine(): DatabaseEngine
    {
        return self::intToEngine($this->engineId);
    }

    public function kind(): BackupKind
    {
        return self::intToKind($this->kindId);
    }

    private static function packLenPrefixed(string $bytes): string
    {
        return pack('N', strlen($bytes)) . $bytes;
    }

    private static function readByte(string $data, int &$offset, int $len, string $field): int
    {
        if ($offset + 1 > $len) {
            throw EnvelopeFormatException::truncated($field);
        }
        $val = ord($data[$offset]);
        $offset++;

        return $val;
    }

    private static function readLenPrefixed(string $data, int &$offset, int $len, string $field): string
    {
        if ($offset + 4 > $len) {
            throw EnvelopeFormatException::truncated($field);
        }
        /** @var array{1: int} $unpacked */
        $unpacked = unpack('N', substr($data, $offset, 4));
        $fieldLen = $unpacked[1];
        $offset += 4;

        if ($fieldLen === 0) {
            throw EnvelopeFormatException::emptyField($field);
        }

        if ($offset + $fieldLen > $len) {
            throw EnvelopeFormatException::truncated($field);
        }
        $val = substr($data, $offset, $fieldLen);
        $offset += $fieldLen;

        return $val;
    }

    private static function codecToInt(CompressionCodec $codec): int
    {
        return match ($codec) {
            CompressionCodec::None => 0x00,
            CompressionCodec::Gzip => 0x01,
            CompressionCodec::Zstd => 0x02,
        };
    }

    private static function intToCodec(int $id): CompressionCodec
    {
        return match ($id) {
            0x00 => CompressionCodec::None,
            0x01 => CompressionCodec::Gzip,
            0x02 => CompressionCodec::Zstd,
            default => throw EnvelopeFormatException::unknownVersion($id),
        };
    }

    private static function engineToInt(DatabaseEngine $engine): int
    {
        return match ($engine) {
            DatabaseEngine::Postgres => 0x01,
            DatabaseEngine::Mongo => 0x02,
        };
    }

    private static function intToEngine(int $id): DatabaseEngine
    {
        return match ($id) {
            0x01 => DatabaseEngine::Postgres,
            0x02 => DatabaseEngine::Mongo,
            default => throw EnvelopeFormatException::unknownVersion($id),
        };
    }

    private static function kindToInt(BackupKind $kind): int
    {
        return match ($kind) {
            BackupKind::LogicalFull => 0x01,
            BackupKind::PhysicalBase => 0x02,
            BackupKind::WalSegment => 0x03,
            BackupKind::MongoArchive => 0x04,
        };
    }

    private static function intToKind(int $id): BackupKind
    {
        return match ($id) {
            0x01 => BackupKind::LogicalFull,
            0x02 => BackupKind::PhysicalBase,
            0x03 => BackupKind::WalSegment,
            0x04 => BackupKind::MongoArchive,
            default => throw EnvelopeFormatException::unknownVersion($id),
        };
    }
}
