<?php

declare(strict_types=1);

namespace Vortos\Migration\Tests\Service;

use PHPUnit\Framework\TestCase;
use Vortos\Migration\Safety\MigrationArtifact;
use Vortos\Migration\Safety\MigrationArtifactFactoryInterface;
use Vortos\Migration\Schema\MigrationOwnership;
use Vortos\Migration\Schema\MigrationPhase;
use Vortos\Migration\Schema\ModuleMigrationDescriptor;
use Vortos\Migration\Service\ModuleMigrationPhaseReader;
use Vortos\Migration\Service\ModuleMigrationRegistryInterface;
use Vortos\Migration\Tests\Fixtures\FakeContractMigration;
use Vortos\Migration\Tests\Fixtures\FakeExpandMigration;
use Vortos\Migration\Tests\Fixtures\FakeNoPhaseMigration;

final class ModuleMigrationPhaseReaderTest extends TestCase
{
    private function createReader(array $descriptorsByClass): ModuleMigrationPhaseReader
    {
        $registry = $this->createMock(ModuleMigrationRegistryInterface::class);
        $registry->method('descriptorsByClass')->willReturn($descriptorsByClass);

        return new ModuleMigrationPhaseReader($registry);
    }

    private function descriptor(string $class): ModuleMigrationDescriptor
    {
        return new ModuleMigrationDescriptor(
            source: 'test.php',
            class: $class,
            module: 'Test',
            filename: 'test.php',
            ownership: new MigrationOwnership([], []),
        );
    }

    public function test_declared_expand_resolves_to_expand(): void
    {
        $reader = $this->createReader([
            FakeExpandMigration::class => $this->descriptor(FakeExpandMigration::class),
        ]);

        self::assertSame(MigrationPhase::Expand, $reader->phaseOf(FakeExpandMigration::class));
    }

    public function test_declared_contract_resolves_to_contract(): void
    {
        $reader = $this->createReader([
            FakeContractMigration::class => $this->descriptor(FakeContractMigration::class),
        ]);

        self::assertSame(MigrationPhase::Contract, $reader->phaseOf(FakeContractMigration::class));
    }

    public function test_absent_attribute_defaults_to_expand(): void
    {
        $reader = $this->createReader([
            FakeNoPhaseMigration::class => $this->descriptor(FakeNoPhaseMigration::class),
        ]);

        self::assertSame(MigrationPhase::Expand, $reader->phaseOf(FakeNoPhaseMigration::class));
    }

    public function test_phases_for_batch_resolution(): void
    {
        $reader = $this->createReader([
            FakeExpandMigration::class => $this->descriptor(FakeExpandMigration::class),
            FakeContractMigration::class => $this->descriptor(FakeContractMigration::class),
            FakeNoPhaseMigration::class => $this->descriptor(FakeNoPhaseMigration::class),
        ]);

        $result = $reader->phasesFor([
            FakeExpandMigration::class,
            FakeContractMigration::class,
            FakeNoPhaseMigration::class,
        ]);

        self::assertSame(MigrationPhase::Expand, $result[FakeExpandMigration::class]);
        self::assertSame(MigrationPhase::Contract, $result[FakeContractMigration::class]);
        self::assertSame(MigrationPhase::Expand, $result[FakeNoPhaseMigration::class]);
    }

    public function test_unknown_id_defaults_to_expand_and_never_throws(): void
    {
        // R7-3: an ID with no loadable class must degrade to the safe default, never throw —
        // a deploy must not die because it cannot name a pending app migration.
        $reader = $this->createReader([]);

        self::assertSame(MigrationPhase::Expand, $reader->phaseOf('NonExistent\\Migration'));
    }

    public function test_phases_for_unknown_id_defaults_to_expand(): void
    {
        $reader = $this->createReader([
            FakeExpandMigration::class => $this->descriptor(FakeExpandMigration::class),
        ]);

        $result = $reader->phasesFor([FakeExpandMigration::class, 'NonExistent\\Migration']);

        self::assertSame(MigrationPhase::Expand, $result[FakeExpandMigration::class]);
        self::assertSame(MigrationPhase::Expand, $result['NonExistent\\Migration']);
    }

    public function test_unannotated_destructive_app_migration_classifies_as_contract(): void
    {
        // No #[DeployPhase], but the up-SQL drops a column → Contract (blocked at deploy time).
        $factory = $this->createMock(MigrationArtifactFactoryInterface::class);
        $factory->method('fromClass')->willReturn(new MigrationArtifact(
            version: 'App\\Migrations\\Version20260706',
            className: 'App\\Migrations\\Version20260706',
            phase: null,
            upSql: ['ALTER TABLE accounts DROP COLUMN legacy'],
            downSql: [],
            hasAllowFullTableRewrite: false,
        ));

        $registry = $this->createMock(ModuleMigrationRegistryInterface::class);
        $registry->method('descriptorsByClass')->willReturn([]);
        $registry->method('descriptorForClass')->willReturn(null);

        $reader = new ModuleMigrationPhaseReader($registry, $factory);

        self::assertSame(MigrationPhase::Contract, $reader->phaseOf('App\\Migrations\\Version20260706'));
        self::assertTrue($reader->isDestructiveAndUnannotated('App\\Migrations\\Version20260706'));
    }

    public function test_destructive_but_opted_out_stays_expand(): void
    {
        $factory = $this->createMock(MigrationArtifactFactoryInterface::class);
        $factory->method('fromClass')->willReturn(new MigrationArtifact(
            version: 'App\\Migrations\\Version20260707',
            className: 'App\\Migrations\\Version20260707',
            phase: null,
            upSql: ['ALTER TABLE accounts DROP COLUMN legacy'],
            downSql: [],
            hasAllowFullTableRewrite: true,
        ));

        $registry = $this->createMock(ModuleMigrationRegistryInterface::class);
        $registry->method('descriptorsByClass')->willReturn([]);
        $registry->method('descriptorForClass')->willReturn(null);

        $reader = new ModuleMigrationPhaseReader($registry, $factory);

        self::assertSame(MigrationPhase::Expand, $reader->phaseOf('App\\Migrations\\Version20260707'));
        self::assertFalse($reader->isDestructiveAndUnannotated('App\\Migrations\\Version20260707'));
    }

    public function test_non_destructive_app_migration_stays_expand(): void
    {
        $factory = $this->createMock(MigrationArtifactFactoryInterface::class);
        $factory->method('fromClass')->willReturn(new MigrationArtifact(
            version: 'App\\Migrations\\Version20260708',
            className: 'App\\Migrations\\Version20260708',
            phase: null,
            upSql: ['ALTER TABLE accounts ADD COLUMN email VARCHAR(255)'],
            downSql: [],
            hasAllowFullTableRewrite: false,
        ));

        $registry = $this->createMock(ModuleMigrationRegistryInterface::class);
        $registry->method('descriptorsByClass')->willReturn([]);
        $registry->method('descriptorForClass')->willReturn(null);

        $reader = new ModuleMigrationPhaseReader($registry, $factory);

        self::assertSame(MigrationPhase::Expand, $reader->phaseOf('App\\Migrations\\Version20260708'));
        self::assertFalse($reader->isDestructiveAndUnannotated('App\\Migrations\\Version20260708'));
    }
}
