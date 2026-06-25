<?php

declare(strict_types=1);

namespace Vortos\Observability\Audit;

/**
 * Streams a signed, tamper-evident deploy-audit export for compliance evidence
 * (SOC2/ISO). Mirrors {@see \Vortos\FeatureFlags\Compliance\Export\AuditExportService}:
 * memory-bounded (rows are never accumulated in RAM), HMAC-signed manifest detached
 * from the export content.
 */
final class AuditExportService
{
    public function __construct(
        private readonly DeployAuditViewRepositoryInterface $repository,
        private readonly string $hmacKey,
        private readonly string $generatorIdentity = 'vortos-observability',
    ) {
        if ($this->hmacKey === '') {
            throw new \InvalidArgumentException('HMAC key must not be empty.');
        }
    }

    /**
     * @param callable(string): void $sink
     */
    public function export(DeployAuditQuery $query, ExportFormat $format, callable $sink): SignedAuditManifest
    {
        $ctx = hash_init('sha256');
        $rowCount = 0;
        $firstAt = null;
        $lastAt = null;

        if ($format === ExportFormat::Csv) {
            $header = $this->csvLine([
                'entry_id', 'sequence', 'event_type', 'actor_id', 'actor_identity_source',
                'env', 'build_id', 'git_sha', 'image_digest', 'schema_fingerprint_id',
                'reason', 'occurred_at', 'content_hash', 'signature',
            ]);
            $sink($header);
            hash_update($ctx, $header);
        }

        foreach ($this->repository->stream($query->env, $query->actorId, $query->from, $query->to) as $entry) {
            $line = match ($format) {
                ExportFormat::Ndjson => $this->toNdjson($entry),
                ExportFormat::Csv => $this->toCsvRow($entry),
            };

            $sink($line);
            hash_update($ctx, $line);

            $firstAt ??= $entry->occurredAt;
            $lastAt = $entry->occurredAt;
            $rowCount++;
        }

        $contentHash = hash_final($ctx);
        $signingMessage = SignedAuditManifest::signingMessage(SignedAuditManifest::SCHEMA_VERSION, $format->value, $rowCount, $contentHash);
        $signature = hash_hmac('sha256', $signingMessage, $this->hmacKey);

        return new SignedAuditManifest(
            schemaVersion: SignedAuditManifest::SCHEMA_VERSION,
            format: $format->value,
            rowCount: $rowCount,
            rangeFrom: $firstAt ?? '',
            rangeTo: $lastAt ?? '',
            generatedAt: (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            generatorIdentity: $this->generatorIdentity,
            contentHash: $contentHash,
            signature: $signature,
        );
    }

    public function verify(DeployAuditQuery $query, ExportFormat $format, SignedAuditManifest $manifest): bool
    {
        $collected = [];
        $this->export($query, $format, static function (string $line) use (&$collected): void {
            $collected[] = $line;
        });

        $reHash = hash('sha256', implode('', $collected));
        if (!hash_equals($reHash, $manifest->contentHash)) {
            return false;
        }

        $signingMessage = SignedAuditManifest::signingMessage($manifest->schemaVersion, $manifest->format, $manifest->rowCount, $manifest->contentHash);
        $expected = hash_hmac('sha256', $signingMessage, $this->hmacKey);

        return hash_equals($expected, $manifest->signature);
    }

    private function toNdjson(AuditEntry $entry): string
    {
        return json_encode($entry->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }

    private function toCsvRow(AuditEntry $entry): string
    {
        return $this->csvLine([
            $entry->entryId,
            (string) $entry->sequence,
            $entry->eventType,
            $entry->actorId,
            $entry->actorIdentitySource,
            $entry->env,
            $entry->buildId,
            $entry->gitSha,
            $entry->imageDigest,
            $entry->schemaFingerprintId,
            $entry->reason ?? '',
            $entry->occurredAt,
            $entry->contentHash,
            $entry->signature,
        ]);
    }

    /** @param string[] $fields */
    private function csvLine(array $fields): string
    {
        $escaped = array_map(
            static fn (string $f) => '"' . str_replace('"', '""', $f) . '"',
            $fields,
        );

        return implode(',', $escaped) . "\n";
    }
}
