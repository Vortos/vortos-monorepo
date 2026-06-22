<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\Compliance;

use PHPUnit\Framework\TestCase;
use Vortos\FeatureFlags\Compliance\Export\AuditExportFilter;
use Vortos\FeatureFlags\Compliance\Export\AuditExportService;
use Vortos\FeatureFlags\Compliance\Export\ExportFormat;
use Vortos\FeatureFlags\Compliance\Export\SignedManifest;
use Vortos\FeatureFlags\ReadModel\FlagAuditEntry;
use Vortos\FeatureFlags\ReadModel\FlagAuditLogRepositoryInterface;

final class AuditExportServiceTest extends TestCase
{
    private const HMAC_KEY = 'test-hmac-key-not-empty';

    private AuditExportService $service;
    private InMemoryAuditLogRepository $repo;

    protected function setUp(): void
    {
        $this->repo    = new InMemoryAuditLogRepository();
        $this->service = new AuditExportService($this->repo, self::HMAC_KEY, 'test-generator');
    }

    // -------------------------------------------------------------------------
    // NDJSON export
    // -------------------------------------------------------------------------

    public function test_ndjson_export_emits_one_json_line_per_entry(): void
    {
        $this->repo->seed($this->makeEntries(5));

        $lines = [];
        $manifest = $this->service->export(new AuditExportFilter(), ExportFormat::Ndjson, function (string $l) use (&$lines): void {
            $lines[] = $l;
        });

        $this->assertCount(5, $lines);
        $this->assertSame(5, $manifest->rowCount);

        foreach ($lines as $line) {
            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $this->assertIsArray($decoded);
            $this->assertArrayHasKey('event_id', $decoded);
            $this->assertArrayHasKey('flag_name', $decoded);
        }
    }

    public function test_csv_export_has_header_plus_data_rows(): void
    {
        $this->repo->seed($this->makeEntries(3));

        $lines = [];
        $manifest = $this->service->export(new AuditExportFilter(), ExportFormat::Csv, function (string $l) use (&$lines): void {
            $lines[] = $l;
        });

        $this->assertCount(4, $lines); // 1 header + 3 data rows
        $this->assertSame(3, $manifest->rowCount);
        $this->assertStringContainsString('event_id', $lines[0]);
        $this->assertStringContainsString('flag_name', $lines[0]);
    }

    // -------------------------------------------------------------------------
    // Signed manifest
    // -------------------------------------------------------------------------

    public function test_manifest_is_signed_and_verifiable(): void
    {
        $this->repo->seed($this->makeEntries(10));

        $lines = [];
        $manifest = $this->service->export(new AuditExportFilter(), ExportFormat::Ndjson, function (string $l) use (&$lines): void {
            $lines[] = $l;
        });

        $this->assertNotEmpty($manifest->contentHash);
        $this->assertNotEmpty($manifest->signature);
        $this->assertSame(SignedManifest::SCHEMA_VERSION, $manifest->schemaVersion);
        $this->assertSame('ndjson', $manifest->format);
        $this->assertSame(10, $manifest->rowCount);

        // Verify passes with correct data
        $verified = $this->service->verify(new AuditExportFilter(), ExportFormat::Ndjson, $manifest);
        $this->assertTrue($verified);
    }

    public function test_tampered_row_count_fails_verification(): void
    {
        $this->repo->seed($this->makeEntries(5));

        $manifest = $this->service->export(new AuditExportFilter(), ExportFormat::Ndjson, static function (string $_): void {});

        // Tamper the manifest: wrong row count
        $tampered = new SignedManifest(
            schemaVersion: $manifest->schemaVersion,
            format: $manifest->format,
            rowCount: $manifest->rowCount + 1, // tampered
            rangeFrom: $manifest->rangeFrom,
            rangeTo: $manifest->rangeTo,
            generatedAt: $manifest->generatedAt,
            generatorIdentity: $manifest->generatorIdentity,
            contentHash: $manifest->contentHash,
            signature: $manifest->signature,
        );

        $this->assertFalse($this->service->verify(new AuditExportFilter(), ExportFormat::Ndjson, $tampered));
    }

    public function test_tampered_content_hash_fails_verification(): void
    {
        $this->repo->seed($this->makeEntries(5));

        $manifest = $this->service->export(new AuditExportFilter(), ExportFormat::Ndjson, static function (string $_): void {});

        $tampered = new SignedManifest(
            schemaVersion: $manifest->schemaVersion,
            format: $manifest->format,
            rowCount: $manifest->rowCount,
            rangeFrom: $manifest->rangeFrom,
            rangeTo: $manifest->rangeTo,
            generatedAt: $manifest->generatedAt,
            generatorIdentity: $manifest->generatorIdentity,
            contentHash: str_repeat('0', 64), // tampered hash
            signature: $manifest->signature,
        );

        $this->assertFalse($this->service->verify(new AuditExportFilter(), ExportFormat::Ndjson, $tampered));
    }

    // -------------------------------------------------------------------------
    // Filtering
    // -------------------------------------------------------------------------

    public function test_filter_by_flag_name(): void
    {
        $entries = array_merge(
            $this->makeEntries(3, ['flag_name' => 'flag-alpha']),
            $this->makeEntries(2, ['flag_name' => 'flag-beta']),
        );
        $this->repo->seed($entries);

        $lines = [];
        $manifest = $this->service->export(
            new AuditExportFilter(flagName: 'flag-alpha'),
            ExportFormat::Ndjson,
            function (string $l) use (&$lines): void { $lines[] = $l; },
        );

        $this->assertSame(3, $manifest->rowCount);
    }

    public function test_filter_by_actor_id(): void
    {
        $entries = array_merge(
            $this->makeEntries(4, ['actor_id' => 'alice']),
            $this->makeEntries(2, ['actor_id' => 'bob']),
        );
        $this->repo->seed($entries);

        $lines = [];
        $manifest = $this->service->export(
            new AuditExportFilter(actorId: 'alice'),
            ExportFormat::Ndjson,
            function (string $l) use (&$lines): void { $lines[] = $l; },
        );

        $this->assertSame(4, $manifest->rowCount);
    }

    // -------------------------------------------------------------------------
    // Memory-bounded streaming: no accumulation
    // -------------------------------------------------------------------------

    public function test_empty_export_produces_manifest_with_zero_rows(): void
    {
        $manifest = $this->service->export(new AuditExportFilter(), ExportFormat::Ndjson, static function (string $_): void {});

        $this->assertSame(0, $manifest->rowCount);
        $this->assertSame('', $manifest->rangeFrom);
    }

    public function test_constructor_rejects_empty_hmac_key(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AuditExportService($this->repo, '');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** @param array<string, mixed> $overrides */
    private function makeEntries(int $count, array $overrides = []): array
    {
        $entries = [];
        for ($i = 0; $i < $count; $i++) {
            $entries[] = new FlagAuditEntry(
                eventId:     bin2hex(random_bytes(8)),
                flagId:      'flag-id',
                flagName:    $overrides['flag_name'] ?? 'test-flag',
                eventType:   'FlagEnabled',
                actorId:     $overrides['actor_id'] ?? 'system',
                reason:      null,
                occurredAt:  (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                data:        [],
                environment: 'production',
            );
        }

        return $entries;
    }
}

/** In-memory audit log for tests — supports the stream() interface. */
final class InMemoryAuditLogRepository implements FlagAuditLogRepositoryInterface
{
    /** @var FlagAuditEntry[] */
    private array $entries = [];

    /** @param FlagAuditEntry[] $entries */
    public function seed(array $entries): void
    {
        $this->entries = array_merge($this->entries, $entries);
    }

    public function upsert(FlagAuditEntry $entry): void
    {
        $this->entries[] = $entry;
    }

    public function findByFlag(string $flagName, int $limit = 100): array
    {
        return array_slice(
            array_values(array_filter($this->entries, fn(FlagAuditEntry $e) => $e->flagName === $flagName)),
            0,
            $limit,
        );
    }

    public function stream(\Vortos\FeatureFlags\Compliance\Export\AuditExportFilter $filter): \Generator
    {
        foreach ($this->entries as $entry) {
            if ($filter->matches($entry)) {
                yield $entry;
            }
        }
    }
}
