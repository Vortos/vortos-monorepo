<?php

declare(strict_types=1);

namespace Vortos\Backup\Service;

use Vortos\Backup\Crypto\EnvelopeFormatException;
use Vortos\Backup\Crypto\EnvelopeHeader;
use Vortos\Backup\Domain\BackupChecksum;
use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Domain\CompressionCodec;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Domain\EncryptionMetadata;
use Vortos\Backup\Domain\Exception\IntegrityException;
use Vortos\Backup\Port\BackupStoreInterface;

/**
 * Verifies a stored backup by reading it back. When the artifact carries encryption
 * metadata, the at-creation check asserts envelope magic + decodable header + wrapped
 * DEK present (over ciphertext) instead of the codec/engine magic. The plaintext
 * format assertion moves into the restore drill, where it is meaningful.
 */
final class IntegrityVerifier
{
    private const GZIP_MAGIC = "\x1f\x8b";
    private const ZSTD_MAGIC = "\x28\xb5\x2f\xfd";
    private const PG_CUSTOM_MAGIC = 'PGDMP';
    private const MONGO_ARCHIVE_MAGIC = "\x6d\xe2\x99\x81";

    public function verify(
        BackupStoreInterface $store,
        string $storeKey,
        BackupChecksum $expected,
        DatabaseEngine $engine,
        BackupKind $kind,
        CompressionCodec $codec,
        ?EncryptionMetadata $encryption = null,
    ): void {
        $stream = $store->open($storeKey);
        if (!is_resource($stream)) {
            throw IntegrityException::unreadable($storeKey);
        }

        try {
            $head = (string) fread($stream, 512);

            if ($encryption !== null || str_starts_with($head, EnvelopeHeader::MAGIC)) {
                $this->assertEnvelopeMagic($storeKey, $head);
            } else {
                $this->assertMagic($storeKey, $head, $engine, $kind, $codec);
            }

            $ctx = hash_init($expected->algorithm);
            hash_update($ctx, $head);
            while (!feof($stream)) {
                $chunk = fread($stream, 1 << 20);
                if ($chunk === false) {
                    throw IntegrityException::unreadable($storeKey);
                }
                hash_update($ctx, $chunk);
            }
            $actual = BackupChecksum::of($expected->algorithm, hash_final($ctx));
        } finally {
            fclose($stream);
        }

        if (!$expected->equals($actual)) {
            throw IntegrityException::checksumMismatch($storeKey, $expected->hex, $actual->hex);
        }
    }

    private function assertEnvelopeMagic(string $key, string $head): void
    {
        if (!str_starts_with($head, EnvelopeHeader::MAGIC)) {
            throw IntegrityException::envelopeMalformed(
                sprintf("stored object '%s' does not start with VBKP1 magic", $key),
            );
        }

        try {
            EnvelopeHeader::decode($head);
        } catch (EnvelopeFormatException $e) {
            throw IntegrityException::envelopeMalformed(
                sprintf("stored object '%s': %s", $key, $e->getMessage()),
            );
        }
    }

    private function assertMagic(
        string $key,
        string $head,
        DatabaseEngine $engine,
        BackupKind $kind,
        CompressionCodec $codec,
    ): void {
        if ($head === '') {
            throw IntegrityException::unrecognisedFormat($key, $engine->value);
        }

        $ok = match ($codec) {
            CompressionCodec::Gzip => str_starts_with($head, self::GZIP_MAGIC),
            CompressionCodec::Zstd => str_starts_with($head, self::ZSTD_MAGIC),
            CompressionCodec::None => $this->matchesEngineMagic($head, $engine, $kind),
        };

        if (!$ok) {
            throw IntegrityException::unrecognisedFormat($key, $engine->value);
        }
    }

    private function matchesEngineMagic(string $head, DatabaseEngine $engine, BackupKind $kind): bool
    {
        return match ([$engine, $kind]) {
            [DatabaseEngine::Postgres, BackupKind::LogicalFull] => str_starts_with($head, self::PG_CUSTOM_MAGIC),
            [DatabaseEngine::Postgres, BackupKind::PhysicalBase] => str_contains(substr($head, 257, 6), 'ustar') || $head !== '',
            [DatabaseEngine::Mongo, BackupKind::MongoArchive] => str_starts_with($head, self::MONGO_ARCHIVE_MAGIC),
            default => $head !== '',
        };
    }
}
