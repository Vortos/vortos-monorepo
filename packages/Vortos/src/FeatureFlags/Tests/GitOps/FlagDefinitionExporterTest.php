<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\GitOps;

use PHPUnit\Framework\TestCase;
use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\FlagRule;
use Vortos\FeatureFlags\FlagValueType;
use Vortos\FeatureFlags\FlagKind;
use Vortos\FeatureFlags\GitOps\FlagDefinitionExporter;
use Vortos\FeatureFlags\Storage\FlagStorageInterface;

final class FlagDefinitionExporterTest extends TestCase
{
    public function test_export_empty_storage_returns_empty_flags(): void
    {
        $storage  = $this->storageWith([]);
        $exporter = new FlagDefinitionExporter($storage);

        $data = $exporter->export();

        $this->assertSame([], $data['flags']);
        $this->assertArrayHasKey('exported_at', $data);
        $this->assertArrayHasKey('version', $data);
        $this->assertStringStartsWith('v1:', $data['version']);
    }

    public function test_export_includes_all_flags_sorted_by_name(): void
    {
        $flags = [
            $this->flag('zebra-flag'),
            $this->flag('alpha-flag'),
            $this->flag('middle-flag'),
        ];

        $exporter = new FlagDefinitionExporter($this->storageWith($flags));
        $data     = $exporter->export();

        $this->assertCount(3, $data['flags']);
        $this->assertSame('alpha-flag', $data['flags'][0]['name']);
        $this->assertSame('middle-flag', $data['flags'][1]['name']);
        $this->assertSame('zebra-flag', $data['flags'][2]['name']);
    }

    public function test_export_strips_timestamps(): void
    {
        $exporter = new FlagDefinitionExporter($this->storageWith([$this->flag('test')]));
        $data     = $exporter->export();

        $this->assertArrayNotHasKey('created_at', $data['flags'][0]);
        $this->assertArrayNotHasKey('updated_at', $data['flags'][0]);
    }

    public function test_export_preserves_flag_properties(): void
    {
        $flag = new FeatureFlag(
            id: 'id-1',
            name: 'my-flag',
            description: 'A test flag',
            enabled: true,
            rules: [new FlagRule(type: FlagRule::TYPE_PERCENTAGE, percentage: 50)],
            variants: ['a' => 50, 'b' => 50],
            createdAt: new \DateTimeImmutable('2026-01-01'),
            updatedAt: new \DateTimeImmutable('2026-06-01'),
            valueType: FlagValueType::String,
            kind: FlagKind::Experiment,
        );

        $exporter = new FlagDefinitionExporter($this->storageWith([$flag]));
        $data     = $exporter->export();

        $exported = $data['flags'][0];
        $this->assertSame('my-flag', $exported['name']);
        $this->assertSame('A test flag', $exported['description']);
        $this->assertTrue($exported['enabled']);
        $this->assertSame('string', $exported['value_type']);
        $this->assertSame('experiment', $exported['kind']);
        $this->assertSame(['a' => 50, 'b' => 50], $exported['variants']);
    }

    public function test_version_is_deterministic(): void
    {
        $flags = [$this->flag('flag-a'), $this->flag('flag-b')];

        $exporter1 = new FlagDefinitionExporter($this->storageWith($flags));
        $exporter2 = new FlagDefinitionExporter($this->storageWith($flags));

        $this->assertSame($exporter1->export()['version'], $exporter2->export()['version']);
    }

    public function test_version_changes_when_flags_change(): void
    {
        $exporter1 = new FlagDefinitionExporter($this->storageWith([$this->flag('a')]));
        $exporter2 = new FlagDefinitionExporter($this->storageWith([$this->flag('a'), $this->flag('b')]));

        $this->assertNotSame($exporter1->export()['version'], $exporter2->export()['version']);
    }

    public function test_render_produces_valid_pretty_json(): void
    {
        $exporter = new FlagDefinitionExporter($this->storageWith([$this->flag('test')]));
        $rendered = $exporter->render();

        $this->assertJson($rendered);
        $this->assertStringEndsWith("\n", $rendered);

        $decoded = json_decode($rendered, true);
        $this->assertArrayHasKey('flags', $decoded);
    }

    public function test_render_is_byte_stable_for_same_input(): void
    {
        $flags = [$this->flag('a'), $this->flag('b')];

        $exporter = new FlagDefinitionExporter($this->storageWith($flags));
        $a        = $exporter->render();
        $b        = $exporter->render();

        $decoded_a = json_decode($a, true);
        $decoded_b = json_decode($b, true);

        unset($decoded_a['exported_at'], $decoded_b['exported_at']);
        $this->assertSame($decoded_a, $decoded_b);
    }

    public function test_export_with_rules_serializes_correctly(): void
    {
        $flag = new FeatureFlag(
            id: 'id-1',
            name: 'targeted-flag',
            description: '',
            enabled: true,
            rules: [
                new FlagRule(type: FlagRule::TYPE_USERS, users: ['u1', 'u2']),
                new FlagRule(type: FlagRule::TYPE_ATTRIBUTE, attribute: 'country', operator: 'eq', value: 'US'),
            ],
            variants: null,
            createdAt: new \DateTimeImmutable(),
            updatedAt: new \DateTimeImmutable(),
        );

        $exporter = new FlagDefinitionExporter($this->storageWith([$flag]));
        $data     = $exporter->export();

        $this->assertCount(2, $data['flags'][0]['rules']);
    }

    private function flag(string $name): FeatureFlag
    {
        $now = new \DateTimeImmutable();

        return new FeatureFlag(
            id: 'id-' . $name,
            name: $name,
            description: '',
            enabled: false,
            rules: [],
            variants: null,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    private function storageWith(array $flags): FlagStorageInterface
    {
        $storage = $this->createMock(FlagStorageInterface::class);
        $storage->method('findAll')->willReturn($flags);

        return $storage;
    }
}
