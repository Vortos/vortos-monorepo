<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Compliance\Export;

/**
 * Detached signed manifest for a streamed audit export.
 *
 * Accompanies an NDJSON/CSV export so recipients can verify:
 *  1. The row count matches what was exported.
 *  2. The canonical hash of the concatenated row stream is unchanged (tamper-evident).
 *  3. The HMAC signature confirms the hash was produced by this server's signing key.
 *
 * The manifest itself is serialized as JSON and shipped alongside the export file.
 */
final class SignedManifest
{
    public const SCHEMA_VERSION = '1';

    public function __construct(
        public readonly string $schemaVersion,
        public readonly string $format,
        public readonly int $rowCount,
        public readonly string $rangeFrom,
        public readonly string $rangeTo,
        public readonly string $generatedAt,
        public readonly string $generatorIdentity,
        /** SHA-256 hash of the canonical export stream (hex). */
        public readonly string $contentHash,
        /** HMAC-SHA256 of "{schemaVersion}:{format}:{rowCount}:{contentHash}" (hex). */
        public readonly string $signature,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version'     => $this->schemaVersion,
            'format'             => $this->format,
            'row_count'          => $this->rowCount,
            'range_from'         => $this->rangeFrom,
            'range_to'           => $this->rangeTo,
            'generated_at'       => $this->generatedAt,
            'generator_identity' => $this->generatorIdentity,
            'content_hash'       => $this->contentHash,
            'signature'          => $this->signature,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    }

    /** Recompute the signature message — used by both signing and verification. */
    public static function signingMessage(
        string $schemaVersion,
        string $format,
        int $rowCount,
        string $contentHash,
    ): string {
        return implode(':', [$schemaVersion, $format, $rowCount, $contentHash]);
    }
}
