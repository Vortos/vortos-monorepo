<?php

declare(strict_types=1);

namespace Vortos\Audit\Export;

/**
 * The outcome of a completed streaming export: where the body and manifest landed in
 * durable storage, the attestable facts (record count, byte size, content hash, the signed
 * manifest), and a time-limited URL the recipient uses to download the body. Unlike the
 * retired in-memory {@see AuditExport}, this never carries the NDJSON payload itself — the
 * payload lives only in the object store.
 */
final readonly class AuditExportResult
{
    /**
     * @param array<string, mixed> $manifest
     */
    public function __construct(
        public string             $bodyKey,
        public string             $manifestKey,
        public int                $recordCount,
        public int                $byteSize,
        public string             $contentSha256,
        public array              $manifest,
        public string             $downloadUrl,
        public \DateTimeImmutable $expiresAt,
    ) {}
}
