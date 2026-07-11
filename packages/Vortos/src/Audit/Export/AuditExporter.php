<?php

declare(strict_types=1);

namespace Vortos\Audit\Export;

use Vortos\Audit\Integrity\AuditHashChain;
use Vortos\Audit\Query\AuditQuery;
use Vortos\Audit\Query\AuditQueryInterface;
use Vortos\Audit\Retention\StoredAuditEventSerializer;

/**
 * Builds a signed, per-tenant (or platform) export of the trail by paging the query
 * reader to exhaustion and attesting the result. Enterprise buyers need this for SOC 2 /
 * ISO evidence: a self-verifying dump of their own audit trail.
 *
 * The manifest is signed over the content hash + descriptive facts, so tampering with
 * either the body or the manifest is detectable with the HMAC key.
 */
final class AuditExporter
{
    public function __construct(
        private readonly AuditQueryInterface        $query,
        private readonly StoredAuditEventSerializer  $serializer,
        private readonly AuditHashChain              $chain,
        private readonly string                      $hmacKey = '',
        private readonly int                         $pageSize = 500,
    ) {}

    public function export(AuditQuery $spec): AuditExport
    {
        $records = [];
        $cursor  = null;

        do {
            $page = $this->query->page($spec->withCursor($cursor)->withLimit($this->pageSize));
            foreach ($page->records as $record) {
                $records[] = $record;
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);

        $ndjson      = $this->serializer->toNdjson(...$records);
        $contentHash = hash('sha256', $ndjson);
        $count       = count($records);

        $manifest = [
            'scope'        => $spec->scope->value,
            'tenant_id'    => $spec->tenantId,
            'from'         => $spec->from?->format('Y-m-d\TH:i:s.uP'),
            'to'           => $spec->to?->format('Y-m-d\TH:i:s.uP'),
            'record_count' => $count,
            'content_sha256' => $contentHash,
            'generated_at' => (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.uP'),
        ];

        $manifest['signature'] = $this->hmacKey !== ''
            ? $this->chain->sign($this->manifestSigningMessage($manifest), $this->hmacKey)
            : '';

        return new AuditExport($ndjson, $manifest);
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function manifestSigningMessage(array $manifest): string
    {
        // Sign the attestable facts only (never the signature field itself).
        return implode('|', [
            $manifest['scope'],
            $manifest['tenant_id'] ?? '',
            $manifest['from'] ?? '',
            $manifest['to'] ?? '',
            (string) $manifest['record_count'],
            $manifest['content_sha256'],
            $manifest['generated_at'],
        ]);
    }
}
