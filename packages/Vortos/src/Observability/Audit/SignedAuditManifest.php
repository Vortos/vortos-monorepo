<?php

declare(strict_types=1);

namespace Vortos\Observability\Audit;

/**
 * A detached, signed manifest accompanying a {@see AuditExportService} export —
 * mirrors {@see \Vortos\FeatureFlags\Compliance\Export\SignedManifest} so SOC2/ISO
 * evidence reviewers see one consistent export-manifest shape across the framework.
 */
final readonly class SignedAuditManifest
{
    public const SCHEMA_VERSION = '1';

    public function __construct(
        public string $schemaVersion,
        public string $format,
        public int $rowCount,
        public string $rangeFrom,
        public string $rangeTo,
        public string $generatedAt,
        public string $generatorIdentity,
        public string $contentHash,
        public string $signature,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'format' => $this->format,
            'row_count' => $this->rowCount,
            'range_from' => $this->rangeFrom,
            'range_to' => $this->rangeTo,
            'generated_at' => $this->generatedAt,
            'generator_identity' => $this->generatorIdentity,
            'content_hash' => $this->contentHash,
            'signature' => $this->signature,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    }

    public static function signingMessage(string $schemaVersion, string $format, int $rowCount, string $contentHash): string
    {
        return implode(':', [$schemaVersion, $format, $rowCount, $contentHash]);
    }
}
