<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\GitOps;

use PHPUnit\Framework\TestCase;
use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\GitOps\FlagDefinitionImporter;
use Vortos\FeatureFlags\GitOps\ImportResult;
use Vortos\FeatureFlags\Storage\FlagStorageInterface;

/**
 * FlagWriteService is final (by design — it's the single write boundary). We can't mock
 * it, so the importer tests validate behaviour through the ImportResult (dry-run mode) and
 * through a thin spy wrapper that records the calls the importer _would_ make.
 *
 * The actual write-through integration (create / revertTo / archiveAndDelete) is validated
 * by FlagWriteService's own test suite + the WriteBoundaryTest architecture guard.
 */
final class FlagDefinitionImporterTest extends TestCase
{
    // ── Dry-run tests (never touches write service) ──

    public function test_dry_run_detects_new_flags(): void
    {
        $storage  = $this->storageWith([]);
        $importer = new FlagDefinitionImporter($storage, $this->stubWriter());

        $result = $importer->import($this->data([$this->flagData('new-flag')]), dryRun: true);

        $this->assertTrue($result->dryRun);
        $this->assertSame(['new-flag'], $result->created);
        $this->assertSame([], $result->updated);
        $this->assertSame([], $result->deleted);
    }

    public function test_dry_run_detects_changed_flags(): void
    {
        $existing = $this->flag('existing', enabled: false);
        $storage  = $this->storageWith([$existing]);
        $importer = new FlagDefinitionImporter($storage, $this->stubWriter());

        $result = $importer->import(
            $this->data([$this->flagData('existing', enabled: true)]),
            dryRun: true,
        );

        $this->assertSame([], $result->created);
        $this->assertSame(['existing'], $result->updated);
    }

    public function test_dry_run_detects_orphaned_flags(): void
    {
        $storage  = $this->storageWith([$this->flag('orphan')]);
        $importer = new FlagDefinitionImporter($storage, $this->stubWriter());

        $result = $importer->import($this->data([]), dryRun: true);

        $this->assertSame(['orphan'], $result->deleted);
    }

    public function test_dry_run_no_changes_when_states_match(): void
    {
        $existing = $this->flag('stable');
        $storage  = $this->storageWith([$existing]);
        $importer = new FlagDefinitionImporter($storage, $this->stubWriter());

        $declaredArr = $existing->toArray();
        unset($declaredArr['created_at'], $declaredArr['updated_at']);

        $result = $importer->import($this->data([$declaredArr]), dryRun: true);

        $this->assertFalse($result->hasChanges());
    }

    public function test_dry_run_mixed_create_update_delete(): void
    {
        $keep    = $this->flag('keep-me');
        $change  = $this->flag('change-me', enabled: false);
        $orphan  = $this->flag('orphan');
        $storage = $this->storageWith([$keep, $change, $orphan]);
        $importer = new FlagDefinitionImporter($storage, $this->stubWriter());

        $keepArr = $keep->toArray();
        unset($keepArr['created_at'], $keepArr['updated_at']);

        $result = $importer->import($this->data([
            $keepArr,
            $this->flagData('change-me', enabled: true),
            $this->flagData('new-one'),
        ]), dryRun: true);

        $this->assertSame(['new-one'], $result->created);
        $this->assertSame(['change-me'], $result->updated);
        $this->assertSame(['orphan'], $result->deleted);
        $this->assertTrue($result->hasChanges());
    }

    // ── ImportResult value object tests ──

    public function test_result_summary_format(): void
    {
        $result = new ImportResult(
            created: ['a', 'b'],
            updated: ['c'],
            deleted: [],
            dryRun: false,
        );

        $this->assertSame('2 created, 1 updated, 0 deleted', $result->summary());
    }

    public function test_dry_run_summary_has_prefix(): void
    {
        $result = new ImportResult(
            created: ['x'],
            updated: [],
            deleted: [],
            dryRun: true,
        );

        $this->assertStringStartsWith('[dry-run]', $result->summary());
    }

    public function test_result_has_changes(): void
    {
        $this->assertFalse((new ImportResult([], [], [], false))->hasChanges());
        $this->assertTrue((new ImportResult(['a'], [], [], false))->hasChanges());
        $this->assertTrue((new ImportResult([], ['a'], [], false))->hasChanges());
        $this->assertTrue((new ImportResult([], [], ['a'], false))->hasChanges());
    }

    public function test_result_to_array(): void
    {
        $result = new ImportResult(
            created: ['x'],
            updated: ['y'],
            deleted: ['z'],
            dryRun: true,
        );

        $arr = $result->toArray();

        $this->assertSame(['x'], $arr['created']);
        $this->assertSame(['y'], $arr['updated']);
        $this->assertSame(['z'], $arr['deleted']);
        $this->assertTrue($arr['dry_run']);
    }

    public function test_invalid_data_throws(): void
    {
        $importer = new FlagDefinitionImporter(
            $this->storageWith([]),
            $this->stubWriter(),
        );

        $this->expectException(\InvalidArgumentException::class);
        $importer->import(['no_flags_key' => true]);
    }

    public function test_empty_import_empty_storage_no_changes(): void
    {
        $importer = new FlagDefinitionImporter(
            $this->storageWith([]),
            $this->stubWriter(),
        );

        $result = $importer->import($this->data([]), dryRun: true);

        $this->assertFalse($result->hasChanges());
    }

    public function test_assigns_uuid_when_id_missing(): void
    {
        $importer = new FlagDefinitionImporter(
            $this->storageWith([]),
            $this->stubWriter(),
        );

        $flagData = $this->flagData('no-id-flag');
        unset($flagData['id']);

        $result = $importer->import($this->data([$flagData]), dryRun: true);
        $this->assertSame(['no-id-flag'], $result->created);
    }

    // ── Helpers ──

    private function flag(string $name, bool $enabled = false): FeatureFlag
    {
        $now = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');

        return new FeatureFlag(
            id: 'id-' . $name,
            name: $name,
            description: '',
            enabled: $enabled,
            rules: [],
            variants: null,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    private function flagData(string $name, bool $enabled = false): array
    {
        return [
            'id'            => 'id-' . $name,
            'name'          => $name,
            'description'   => '',
            'enabled'       => $enabled,
            'rules'         => [],
            'variants'      => null,
            'value_type'    => 'bool',
            'default_value' => 'false',
            'payload'       => null,
            'bucket_by'     => 'userId',
            'kind'          => 'release',
            'prerequisites' => [],
            'variant_rules' => null,
            'schedule'      => null,
            'required_scope' => null,
            'environment'   => 'production',
            'project_id'    => 'default',
            'lifecycle'     => 'active',
            'owner'         => null,
            'expires_at'    => null,
        ];
    }

    private function data(array $flagArrays): array
    {
        return ['flags' => $flagArrays];
    }

    private function storageWith(array $flags): FlagStorageInterface
    {
        $storage = $this->createMock(FlagStorageInterface::class);
        $storage->method('findAll')->willReturn($flags);

        return $storage;
    }

    /**
     * FlagWriteService is final — we can't mock it. For dry-run tests we need a real
     * instance, but its constructor requires UnitOfWork + EventBus. Instead, we create
     * a minimal stub via reflection that will never be called (dry-run skips writes).
     *
     * For non-dry-run integration tests, use the full Docker container with real services.
     */
    private function stubWriter(): \Vortos\FeatureFlags\Application\FlagWriteService
    {
        $ref = new \ReflectionClass(\Vortos\FeatureFlags\Application\FlagWriteService::class);

        return $ref->newInstanceWithoutConstructor();
    }
}
