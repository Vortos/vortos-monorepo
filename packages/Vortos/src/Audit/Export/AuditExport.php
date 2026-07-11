<?php

declare(strict_types=1);

namespace Vortos\Audit\Export;

/**
 * A completed export: the NDJSON body plus a signed manifest describing and attesting to
 * it. The manifest lets a recipient (auditor, the tenant's own compliance team) confirm
 * the export is complete and unaltered: record count, time range, a SHA-256 of the body,
 * and an HMAC signature over those facts.
 */
final readonly class AuditExport
{
    /**
     * @param array<string, mixed> $manifest
     */
    public function __construct(
        public string $ndjson,
        public array  $manifest,
    ) {}
}
