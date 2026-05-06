<?php

declare(strict_types=1);

namespace Vortos\Tests\Migration\Service;

use PHPUnit\Framework\TestCase;
use Vortos\Migration\Schema\MigrationDriftReport;
use Vortos\Migration\Schema\MigrationOwnership;
use Vortos\Migration\Schema\ModuleMigrationDescriptor;
use Vortos\Migration\Service\MigrationDriftDetector;
use Vortos\Migration\Service\MigrationSchemaInspectorInterface;

final class MigrationDriftDetectorTest extends TestCase
{
    public function test_reports_clean_when_no_owned_objects_exist(): void
    {
        $report = $this->detector(
            existingTables: [],
            missingTables: ['orders'],
            existingIndexes: [],
            missingIndexes: ['idx_orders_status'],
        )->detect($this->descriptor());

        $this->assertSame(MigrationDriftReport::Clean, $report->status());
        $this->assertFalse($report->blocksMigration());
    }

    public function test_reports_compatible_existing_when_all_owned_objects_exist(): void
    {
        $report = $this->detector(
            existingTables: ['orders'],
            missingTables: [],
            existingIndexes: ['idx_orders_status'],
            missingIndexes: [],
        )->detect($this->descriptor());

        $this->assertSame(MigrationDriftReport::CompatibleExisting, $report->status());
        $this->assertTrue($report->blocksMigration());
    }

    public function test_reports_partial_when_some_owned_objects_are_missing(): void
    {
        $report = $this->detector(
            existingTables: ['orders'],
            missingTables: [],
            existingIndexes: [],
            missingIndexes: ['idx_orders_status'],
        )->detect($this->descriptor());

        $this->assertSame(MigrationDriftReport::Partial, $report->status());
        $this->assertTrue($report->blocksMigration());
        $this->assertSame(['idx_orders_status'], $report->missingIndexes());
    }

    public function test_reports_unknown_when_migration_has_no_ownership_metadata(): void
    {
        $report = $this->detector()->detect(new ModuleMigrationDescriptor(
            source: 'custom.sql',
            class: 'App\\Migrations\\Version1',
            module: 'Unknown',
            filename: 'custom.sql',
            ownership: new MigrationOwnership(),
        ));

        $this->assertSame(MigrationDriftReport::Unknown, $report->status());
        $this->assertFalse($report->blocksMigration());
    }

    private function descriptor(): ModuleMigrationDescriptor
    {
        return new ModuleMigrationDescriptor(
            source: 'orders.php',
            class: 'App\\Migrations\\Version1',
            module: 'Orders',
            filename: 'orders.php',
            ownership: new MigrationOwnership(['orders'], ['idx_orders_status']),
        );
    }

    /**
     * @param string[] $existingTables
     * @param string[] $missingTables
     * @param string[] $existingIndexes
     * @param string[] $missingIndexes
     * @param array<string, string[]> $missingColumns
     */
    private function detector(
        array $existingTables = [],
        array $missingTables = [],
        array $existingIndexes = [],
        array $missingIndexes = [],
        array $missingColumns = [],
    ): MigrationDriftDetector {
        $inspector = new class($existingTables, $missingTables, $existingIndexes, $missingIndexes, $missingColumns) implements MigrationSchemaInspectorInterface {
            public function __construct(
                private readonly array $existingTables,
                private readonly array $missingTables,
                private readonly array $existingIndexes,
                private readonly array $missingIndexes,
                private readonly array $missingColumns,
            ) {
            }

            public function existingTables(ModuleMigrationDescriptor $descriptor): array
            {
                return $this->existingTables;
            }

            public function missingTables(ModuleMigrationDescriptor $descriptor): array
            {
                return $this->missingTables;
            }

            public function existingIndexes(ModuleMigrationDescriptor $descriptor): array
            {
                return $this->existingIndexes;
            }

            public function missingIndexes(ModuleMigrationDescriptor $descriptor): array
            {
                return $this->missingIndexes;
            }

            public function missingColumns(ModuleMigrationDescriptor $descriptor): array
            {
                return $this->missingColumns;
            }
        };

        return new MigrationDriftDetector($inspector);
    }
}
