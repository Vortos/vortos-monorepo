<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\GitOps;

use PHPUnit\Framework\TestCase;
use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\GitOps\DriftType;
use Vortos\FeatureFlags\GitOps\GitOpsDriftService;
use Vortos\FeatureFlags\Storage\FlagStorageInterface;

final class GitOpsDriftServiceTest extends TestCase
{
    public function test_no_drift_when_states_match(): void
    {
        $flag    = $this->flag('my-flag');
        $storage = $this->storageWith([$flag]);

        $service = new GitOpsDriftService($storage);

        $flagArr = $flag->toArray();
        unset($flagArr['created_at'], $flagArr['updated_at']);

        $report = $service->detect(['flags' => [$flagArr]]);

        $this->assertFalse($report->hasDrift());
        $this->assertSame(0, $report->count());
        $this->assertSame('No drift detected', $report->summary());
    }

    public function test_detects_field_mismatch(): void
    {
        $flag    = $this->flag('my-flag', enabled: false);
        $storage = $this->storageWith([$flag]);

        $service = new GitOpsDriftService($storage);

        $flagArr = $flag->toArray();
        unset($flagArr['created_at'], $flagArr['updated_at']);
        $flagArr['enabled'] = true;

        $report = $service->detect(['flags' => [$flagArr]]);

        $this->assertTrue($report->hasDrift());
        $this->assertCount(1, $report->entries);

        $entry = $report->entries[0];
        $this->assertSame('my-flag', $entry->flagName);
        $this->assertSame(DriftType::FieldMismatch, $entry->type);
        $this->assertArrayHasKey('enabled', $entry->fields);
        $this->assertFalse($entry->fields['enabled']['effective']);
        $this->assertTrue($entry->fields['enabled']['declared']);
    }

    public function test_detects_missing_in_runtime(): void
    {
        $storage = $this->storageWith([]);

        $service = new GitOpsDriftService($storage);
        $report  = $service->detect(['flags' => [$this->flagData('ghost-flag')]]);

        $this->assertTrue($report->hasDrift());
        $this->assertCount(1, $report->entries);
        $this->assertSame(DriftType::MissingInRuntime, $report->entries[0]->type);
        $this->assertSame('ghost-flag', $report->entries[0]->flagName);
    }

    public function test_detects_undeclared_in_file(): void
    {
        $flag    = $this->flag('rogue-flag');
        $storage = $this->storageWith([$flag]);

        $service = new GitOpsDriftService($storage);
        $report  = $service->detect(['flags' => []]);

        $this->assertTrue($report->hasDrift());
        $this->assertCount(1, $report->entries);
        $this->assertSame(DriftType::UndeclaredInFile, $report->entries[0]->type);
        $this->assertSame('rogue-flag', $report->entries[0]->flagName);
    }

    public function test_mixed_drift_categories(): void
    {
        $matching = $this->flag('matched');
        $changed  = $this->flag('changed', enabled: false);
        $rogue    = $this->flag('rogue');

        $storage = $this->storageWith([$matching, $changed, $rogue]);

        $matchedArr = $matching->toArray();
        unset($matchedArr['created_at'], $matchedArr['updated_at']);

        $changedArr = $changed->toArray();
        unset($changedArr['created_at'], $changedArr['updated_at']);
        $changedArr['enabled'] = true;

        $service = new GitOpsDriftService($storage);
        $report  = $service->detect([
            'flags' => [
                $matchedArr,
                $changedArr,
                $this->flagData('missing-runtime'),
            ],
        ]);

        $this->assertTrue($report->hasDrift());

        $mismatches = $report->ofType(DriftType::FieldMismatch);
        $missing    = $report->ofType(DriftType::MissingInRuntime);
        $undeclared = $report->ofType(DriftType::UndeclaredInFile);

        $this->assertCount(1, $mismatches);
        $this->assertSame('changed', $mismatches[0]->flagName);

        $this->assertCount(1, $missing);
        $this->assertSame('missing-runtime', $missing[0]->flagName);

        $this->assertCount(1, $undeclared);
        $this->assertSame('rogue', $undeclared[0]->flagName);
    }

    public function test_summary_includes_all_categories(): void
    {
        $rogue  = $this->flag('rogue');
        $storage = $this->storageWith([$rogue]);

        $service = new GitOpsDriftService($storage);
        $report  = $service->detect(['flags' => [$this->flagData('ghost')]]);

        $summary = $report->summary();
        $this->assertStringContainsString('missing in runtime', $summary);
        $this->assertStringContainsString('undeclared in file', $summary);
    }

    public function test_drift_entry_to_array(): void
    {
        $flag    = $this->flag('f1', enabled: false);
        $storage = $this->storageWith([$flag]);

        $flagArr = $flag->toArray();
        unset($flagArr['created_at'], $flagArr['updated_at']);
        $flagArr['description'] = 'changed';

        $service = new GitOpsDriftService($storage);
        $report  = $service->detect(['flags' => [$flagArr]]);

        $arr = $report->toArray();
        $this->assertCount(1, $arr);
        $this->assertSame('f1', $arr[0]['flag']);
        $this->assertSame('field_mismatch', $arr[0]['type']);
        $this->assertArrayHasKey('fields', $arr[0]);
    }

    public function test_ignores_timestamp_differences(): void
    {
        $flag    = $this->flag('time-flag');
        $storage = $this->storageWith([$flag]);

        $service = new GitOpsDriftService($storage);

        $flagArr = $flag->toArray();
        unset($flagArr['created_at'], $flagArr['updated_at']);

        $report = $service->detect(['flags' => [$flagArr]]);

        $this->assertFalse($report->hasDrift());
    }

    public function test_empty_declared_and_empty_runtime_no_drift(): void
    {
        $storage = $this->storageWith([]);
        $service = new GitOpsDriftService($storage);

        $report = $service->detect(['flags' => []]);

        $this->assertFalse($report->hasDrift());
    }

    public function test_multiple_field_mismatches_in_single_flag(): void
    {
        $flag    = $this->flag('multi-drift', enabled: false);
        $storage = $this->storageWith([$flag]);

        $flagArr = $flag->toArray();
        unset($flagArr['created_at'], $flagArr['updated_at']);
        $flagArr['enabled']     = true;
        $flagArr['description'] = 'changed desc';

        $service = new GitOpsDriftService($storage);
        $report  = $service->detect(['flags' => [$flagArr]]);

        $this->assertCount(1, $report->entries);
        $this->assertArrayHasKey('enabled', $report->entries[0]->fields);
        $this->assertArrayHasKey('description', $report->entries[0]->fields);
    }

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

    private function flagData(string $name): array
    {
        return [
            'name'        => $name,
            'description' => '',
            'enabled'     => false,
            'rules'       => [],
            'variants'    => null,
            'value_type'  => 'bool',
            'default_value' => 'false',
            'payload'     => null,
            'bucket_by'   => 'userId',
            'kind'        => 'release',
            'prerequisites' => [],
            'variant_rules' => null,
            'schedule'    => null,
            'required_scope' => null,
            'environment' => 'production',
            'project_id'  => 'default',
            'lifecycle'   => 'active',
            'owner'       => null,
            'expires_at'  => null,
        ];
    }

    private function storageWith(array $flags): FlagStorageInterface
    {
        $storage = $this->createMock(FlagStorageInterface::class);
        $storage->method('findAll')->willReturn($flags);

        return $storage;
    }
}
