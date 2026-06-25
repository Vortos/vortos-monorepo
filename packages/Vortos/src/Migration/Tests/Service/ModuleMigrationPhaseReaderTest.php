<?php

declare(strict_types=1);

namespace Vortos\Migration\Tests\Service;

use PHPUnit\Framework\TestCase;
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

    public function test_unknown_id_throws(): void
    {
        $reader = $this->createReader([]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unknown migration ID/');

        $reader->phaseOf('NonExistent\\Migration');
    }

    public function test_phases_for_unknown_id_throws(): void
    {
        $reader = $this->createReader([
            FakeExpandMigration::class => $this->descriptor(FakeExpandMigration::class),
        ]);

        $this->expectException(\InvalidArgumentException::class);

        $reader->phasesFor([FakeExpandMigration::class, 'NonExistent\\Migration']);
    }
}
