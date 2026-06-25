<?php

declare(strict_types=1);

namespace Vortos\Migration\Tests\Service;

use PHPUnit\Framework\TestCase;
use Vortos\Migration\Schema\MigrationOwnership;
use Vortos\Migration\Schema\ModuleMigrationDescriptor;
use Vortos\Migration\Service\ModuleFlagGateMetadataReader;
use Vortos\Migration\Service\ModuleMigrationRegistryInterface;
use Vortos\Migration\Tests\Fixtures\FakeContractMigration;
use Vortos\Migration\Tests\Fixtures\FakeFlagGatedContractMigration;

final class ModuleFlagGateMetadataReaderTest extends TestCase
{
    private function createReader(array $descriptorsByClass): ModuleFlagGateMetadataReader
    {
        $registry = $this->createMock(ModuleMigrationRegistryInterface::class);
        $registry->method('descriptorsByClass')->willReturn($descriptorsByClass);

        return new ModuleFlagGateMetadataReader($registry);
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

    public function test_declared_gate_resolves_to_spec(): void
    {
        $reader = $this->createReader([
            FakeFlagGatedContractMigration::class => $this->descriptor(FakeFlagGatedContractMigration::class),
        ]);

        $spec = $reader->flagGateFor(FakeFlagGatedContractMigration::class);

        self::assertNotNull($spec);
        self::assertSame('drop-email-old', $spec->flagName);
        self::assertSame('legacy', $spec->oldVariant);
    }

    public function test_absent_attribute_resolves_to_null(): void
    {
        $reader = $this->createReader([
            FakeContractMigration::class => $this->descriptor(FakeContractMigration::class),
        ]);

        self::assertNull($reader->flagGateFor(FakeContractMigration::class));
    }

    public function test_unknown_id_resolves_to_null(): void
    {
        $reader = $this->createReader([]);

        self::assertNull($reader->flagGateFor('NonExistent\\Migration'));
    }
}
