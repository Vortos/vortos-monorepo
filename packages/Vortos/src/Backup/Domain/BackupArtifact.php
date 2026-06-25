<?php

declare(strict_types=1);

namespace Vortos\Backup\Domain;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Vortos\Backup\Domain\EncryptionMetadata;

/**
 * The immutable record of one stored, verified backup.
 *
 * Every artifact in the catalog has already passed integrity verification at
 * creation, so the catalog is, by construction, a list of *known-good* backups —
 * which is exactly what retention and restore reason over.
 *
 * Carries an optional {@see $schemaFingerprint} (the Block-3 release fingerprint) so
 * "which migration set does this backup correspond to" is answerable from the
 * backup identity alone — the input a Block-20 restore drill needs to assert schema
 * compatibility before restoring into a live environment.
 */
final readonly class BackupArtifact
{
    public function __construct(
        public BackupId $id,
        public DatabaseEngine $engine,
        public BackupKind $kind,
        public string $environment,
        public DateTimeImmutable $createdAt,
        public int $sizeBytes,
        public BackupChecksum $checksum,
        public string $storeKey,
        public CompressionCodec $codec,
        public SourceRef $sourceRef,
        public ?string $parentId = null,
        public ?string $schemaFingerprint = null,
        public ?EncryptionMetadata $encryption = null,
        public ?string $secondaryStoreKey = null,
    ) {
        if ($environment === '') {
            throw new InvalidArgumentException('Backup environment must be non-empty.');
        }
        if ($sizeBytes < 0) {
            throw new InvalidArgumentException('Backup sizeBytes must be >= 0.');
        }
        if ($storeKey === '') {
            throw new InvalidArgumentException('Backup storeKey must be non-empty.');
        }
    }

    public function isRestorePoint(): bool
    {
        return $this->kind->isRestorePoint();
    }

    public function isWalSegment(): bool
    {
        return $this->kind->isWalSegment();
    }

    /**
     * @return array{
     *   id:string, engine:string, kind:string, environment:string, created_at:string,
     *   size_bytes:int, checksum_algo:string, checksum_hex:string, store_key:string,
     *   codec:string, source_ref:array{type:string,value:?string},
     *   parent_id:?string, schema_fingerprint:?string,
     *   encryption_provider:?string, encryption_recipient:?string, encryption_aead_id:?int,
     *   secondary_store_key:?string
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id->value(),
            'engine' => $this->engine->value,
            'kind' => $this->kind->value,
            'environment' => $this->environment,
            'created_at' => $this->createdAt->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),
            'size_bytes' => $this->sizeBytes,
            'checksum_algo' => $this->checksum->algorithm,
            'checksum_hex' => $this->checksum->hex,
            'store_key' => $this->storeKey,
            'codec' => $this->codec->value,
            'source_ref' => $this->sourceRef->toArray(),
            'parent_id' => $this->parentId,
            'schema_fingerprint' => $this->schemaFingerprint,
            'encryption_provider' => $this->encryption?->provider,
            'encryption_recipient' => $this->encryption?->recipientId,
            'encryption_aead_id' => $this->encryption?->aeadId,
            'secondary_store_key' => $this->secondaryStoreKey,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        $sourceRef = $row['source_ref'] ?? null;
        if (is_string($sourceRef)) {
            /** @var array{type?:string,value?:?string} $decoded */
            $decoded = json_decode($sourceRef, true) ?: [];
            $sourceRef = $decoded;
        }

        $encryption = null;
        if (isset($row['encryption_provider']) && $row['encryption_provider'] !== '') {
            $encryption = new EncryptionMetadata(
                (string) $row['encryption_provider'],
                (string) ($row['encryption_recipient'] ?? ''),
                (int) ($row['encryption_aead_id'] ?? 0),
            );
        }

        return new self(
            BackupId::fromString((string) $row['id']),
            DatabaseEngine::from((string) $row['engine']),
            BackupKind::from((string) $row['kind']),
            (string) $row['environment'],
            new DateTimeImmutable((string) $row['created_at']),
            (int) $row['size_bytes'],
            BackupChecksum::of((string) $row['checksum_algo'], (string) $row['checksum_hex']),
            (string) $row['store_key'],
            CompressionCodec::from((string) $row['codec']),
            SourceRef::fromArray(is_array($sourceRef) ? $sourceRef : []),
            (isset($row['parent_id']) && $row['parent_id'] !== '') ? (string) $row['parent_id'] : null,
            (isset($row['schema_fingerprint']) && $row['schema_fingerprint'] !== '') ? (string) $row['schema_fingerprint'] : null,
            $encryption,
            (isset($row['secondary_store_key']) && $row['secondary_store_key'] !== '') ? (string) $row['secondary_store_key'] : null,
        );
    }
}
