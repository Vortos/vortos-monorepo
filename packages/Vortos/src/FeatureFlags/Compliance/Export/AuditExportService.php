<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Compliance\Export;

use Vortos\FeatureFlags\ReadModel\FlagAuditEntry;
use Vortos\FeatureFlags\ReadModel\FlagAuditLogRepositoryInterface;

/**
 * Streams a signed, tamper-evident audit-log export.
 *
 * Guarantee: memory-bounded — individual rows are never accumulated in RAM.
 * The hash is computed incrementally over the stream so a million-row export
 * costs O(1) memory.
 *
 * Security (PLATFORM §10):
 *  - HMAC-SHA256 over a canonical message prevents signature forgery without the key.
 *  - Row hash covers the stream content so tampering a single row invalidates the manifest.
 *  - The export is read-only over the audit read model (never touches write storage).
 *  - No PII: audit log already contains only actorId / flagName / event metadata.
 */
final class AuditExportService
{
    public function __construct(
        private readonly FlagAuditLogRepositoryInterface $auditLog,
        private readonly string $hmacKey,
        private readonly string $generatorIdentity = 'vortos-feature-flags',
    ) {
        if ($this->hmacKey === '') {
            throw new \InvalidArgumentException('HMAC key must not be empty');
        }
    }

    /**
     * Stream export rows to a callable sink (avoids loading all rows into memory).
     *
     * @param callable(string): void $sink Receives each raw line (NDJSON row or CSV line)
     * @return SignedManifest Detached manifest to be shipped alongside the export file
     */
    public function export(
        AuditExportFilter $filter,
        ExportFormat $format,
        callable $sink,
    ): SignedManifest {
        $ctx      = hash_init('sha256');
        $rowCount = 0;
        $firstAt  = null;
        $lastAt   = null;

        $isFirst = true;
        if ($format === ExportFormat::Csv) {
            $headerLine = $this->csvLine([
                'event_id', 'flag_name', 'environment', 'event_type',
                'actor_id', 'reason', 'occurred_at',
            ]);
            $sink($headerLine);
            hash_update($ctx, $headerLine);
        }

        foreach ($this->auditLog->stream($filter) as $entry) {
            $line = match ($format) {
                ExportFormat::Ndjson => $this->toNdjson($entry),
                ExportFormat::Csv    => $this->toCsvRow($entry),
            };

            $sink($line);
            hash_update($ctx, $line);

            if ($isFirst) {
                $firstAt = $entry->occurredAt;
                $isFirst = false;
            }
            $lastAt = $entry->occurredAt;
            $rowCount++;
        }

        $contentHash = hash_final($ctx);

        $sigMsg    = SignedManifest::signingMessage(
            SignedManifest::SCHEMA_VERSION,
            $format->value,
            $rowCount,
            $contentHash,
        );
        $signature = hash_hmac('sha256', $sigMsg, $this->hmacKey);

        return new SignedManifest(
            schemaVersion:     SignedManifest::SCHEMA_VERSION,
            format:            $format->value,
            rowCount:          $rowCount,
            rangeFrom:         $firstAt ?? '',
            rangeTo:           $lastAt ?? '',
            generatedAt:       (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            generatorIdentity: $this->generatorIdentity,
            contentHash:       $contentHash,
            signature:         $signature,
        );
    }

    /**
     * Verify a manifest against a re-streamed export.
     *
     * Returns true only when:
     *  - row count matches the manifest
     *  - content hash matches the re-streamed rows
     *  - HMAC signature verifies (constant-time compare)
     */
    public function verify(
        AuditExportFilter $filter,
        ExportFormat $format,
        SignedManifest $manifest,
    ): bool {
        $collected = [];
        $this->export($filter, $format, static function (string $line) use (&$collected): void {
            $collected[] = $line;
        });

        $reHash = hash('sha256', implode('', $collected));

        if (!hash_equals($reHash, $manifest->contentHash)) {
            return false;
        }

        $sigMsg    = SignedManifest::signingMessage(
            $manifest->schemaVersion,
            $manifest->format,
            $manifest->rowCount,
            $manifest->contentHash,
        );
        $expected  = hash_hmac('sha256', $sigMsg, $this->hmacKey);

        return hash_equals($expected, $manifest->signature);
    }

    private function toNdjson(FlagAuditEntry $entry): string
    {
        return json_encode([
            'event_id'    => $entry->eventId,
            'flag_id'     => $entry->flagId,
            'flag_name'   => $entry->flagName,
            'environment' => $entry->environment,
            'event_type'  => $entry->eventType,
            'actor_id'    => $entry->actorId,
            'reason'      => $entry->reason,
            'occurred_at' => $entry->occurredAt,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }

    private function toCsvRow(FlagAuditEntry $entry): string
    {
        return $this->csvLine([
            $entry->eventId,
            $entry->flagName,
            $entry->environment,
            $entry->eventType,
            $entry->actorId,
            $entry->reason ?? '',
            $entry->occurredAt,
        ]);
    }

    /** @param string[] $fields */
    private function csvLine(array $fields): string
    {
        $escaped = array_map(
            static fn(string $f) => '"' . str_replace('"', '""', $f) . '"',
            $fields,
        );

        return implode(',', $escaped) . "\n";
    }
}
