<?php

declare(strict_types=1);

namespace Vortos\Audit\Export;

use Psr\Clock\ClockInterface;
use Vortos\Audit\Integrity\AuditHashChain;
use Vortos\Audit\Query\AuditQuery;
use Vortos\Audit\Query\AuditQueryInterface;
use Vortos\Audit\Retention\StoredAuditEventSerializer;

/**
 * Builds a signed, per-tenant (or platform) export of the trail WITHOUT ever holding the
 * whole trail in memory. It pages the query reader to exhaustion, streaming each page's
 * NDJSON straight into a spill-to-disk temp stream while a running SHA-256 accumulates, then
 * hands the stream to an {@see AuditExportSinkInterface} (object store) and mints a
 * time-limited download URL. This replaces the previous in-memory {@see AuditExporter}, which
 * accumulated every record into a PHP array and returned the entire NDJSON string in the HTTP
 * response — unbounded, and the exact thing the rest of the audit spine is engineered to avoid
 * (cf. AuditAdminService::verifyChain, which streams batch-by-batch for the same reason).
 *
 * The manifest is byte-for-byte compatible with the old exporter's: same fields, same signing
 * message, same HMAC — so an export produced here verifies identically. Only the transport
 * changed (object store + signed URL instead of an inline response body).
 */
final class StreamingAuditExporter
{
    /**
     * php://temp keeps the body in RAM up to this threshold, then transparently spills to a
     * temp file — so peak memory is bounded no matter how large the export grows.
     */
    private const MEMORY_SPILL_BYTES = 8 * 1024 * 1024;

    public function __construct(
        private readonly AuditQueryInterface       $query,
        private readonly StoredAuditEventSerializer $serializer,
        private readonly AuditHashChain             $chain,
        private readonly AuditExportSinkInterface   $sink,
        private readonly ClockInterface             $clock,
        private readonly string                     $hmacKey = '',
        private readonly string                     $keyPrefix = 'audit-exports',
        private readonly int                        $pageSize = 500,
        private readonly int                        $downloadTtlSeconds = 3600,
    ) {}

    /**
     * Run one export for the given filter spec, tagged with $exportId (used as the object-key
     * leaf so a job's body/manifest are addressable and collision-free).
     */
    public function export(AuditQuery $spec, string $exportId): AuditExportResult
    {
        $stream = fopen('php://temp/maxmemory:' . self::MEMORY_SPILL_BYTES, 'w+b');
        if ($stream === false) {
            throw new \RuntimeException('Unable to open a temporary stream for the audit export body.');
        }

        try {
            $hashCtx  = hash_init('sha256');
            $cursor   = $spec->cursor;
            $count    = 0;
            $wroteAny = false;

            do {
                $page = $this->query->page($spec->withCursor($cursor)->withLimit($this->pageSize));

                if ($page->records !== []) {
                    // Lines within a page are joined by "\n" by the serializer; join pages with
                    // a leading "\n" so the full body equals implode("\n", allLines) — identical
                    // to what the old exporter serialized in one shot. This keeps the content
                    // hash (and therefore the signature) stable across the sync→streaming change.
                    $chunk = $this->serializer->toNdjson(...$page->records);
                    $piece = $wroteAny ? "\n" . $chunk : $chunk;

                    if (fwrite($stream, $piece) === false) {
                        throw new \RuntimeException('Failed writing audit export body to the temporary stream.');
                    }
                    hash_update($hashCtx, $piece);

                    $count   += \count($page->records);
                    $wroteAny = true;
                }

                $cursor = $page->nextCursor;
            } while ($cursor !== null);

            $contentHash = hash_final($hashCtx);
            $byteSize    = (int) (ftell($stream) ?: 0);
            rewind($stream);

            $generatedAt = $this->clock->now();
            $manifest    = $this->buildManifest($spec, $count, $contentHash, $generatedAt);

            $bodyKey     = $this->objectKey($spec, $exportId, 'ndjson');
            $manifestKey = $this->objectKey($spec, $exportId, 'manifest.json');

            $this->sink->put($bodyKey, $stream, 'application/x-ndjson', $exportId . '.ndjson');
            $this->sink->put(
                $manifestKey,
                json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'application/json',
                $exportId . '.manifest.json',
            );

            $expiresAt   = $generatedAt->add(new \DateInterval('PT' . $this->downloadTtlSeconds . 'S'));
            $downloadUrl = $this->sink->temporaryDownloadUrl($bodyKey, $expiresAt);

            return new AuditExportResult(
                bodyKey:       $bodyKey,
                manifestKey:   $manifestKey,
                recordCount:   $count,
                byteSize:      $byteSize,
                contentSha256: $contentHash,
                manifest:      $manifest,
                downloadUrl:   $downloadUrl,
                expiresAt:     $expiresAt,
            );
        } finally {
            if (\is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildManifest(
        AuditQuery         $spec,
        int                $count,
        string             $contentHash,
        \DateTimeImmutable $generatedAt,
    ): array {
        $manifest = [
            'scope'          => $spec->scope->value,
            'tenant_id'      => $spec->tenantId,
            'from'           => $spec->from?->format('Y-m-d\TH:i:s.uP'),
            'to'             => $spec->to?->format('Y-m-d\TH:i:s.uP'),
            'record_count'   => $count,
            'content_sha256' => $contentHash,
            'generated_at'   => $generatedAt->format('Y-m-d\TH:i:s.uP'),
        ];

        $manifest['signature'] = $this->hmacKey !== ''
            ? $this->chain->sign($this->manifestSigningMessage($manifest), $this->hmacKey)
            : '';

        return $manifest;
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function manifestSigningMessage(array $manifest): string
    {
        // Sign the attestable facts only (never the signature field itself). Byte-identical to
        // the retired AuditExporter so historical + new exports share one verification path.
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

    private function objectKey(AuditQuery $spec, string $exportId, string $ext): string
    {
        // {prefix}/tenant/{tenantId}/{exportId}.{ext} or {prefix}/platform/{exportId}.{ext} —
        // sortable and scope-partitioned, matching the archive writer's key convention.
        $chainPath = $spec->scope->requiresTenantId()
            ? 'tenant/' . (string) $spec->tenantId
            : 'platform';

        return sprintf('%s/%s/%s.%s', trim($this->keyPrefix, '/'), $chainPath, $exportId, $ext);
    }
}
