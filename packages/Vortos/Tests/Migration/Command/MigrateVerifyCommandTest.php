<?php

declare(strict_types=1);

namespace Vortos\Tests\Migration\Command;

use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Metadata\ExecutedMigration;
use Doctrine\Migrations\Metadata\ExecutedMigrationsList;
use Doctrine\Migrations\Metadata\Storage\MetadataStorage;
use Doctrine\Migrations\Version\Version;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\Migration\Command\MigrateVerifyCommand;
use Vortos\Migration\Schema\MigrationDriftReport;
use Vortos\Migration\Schema\MigrationOwnership;
use Vortos\Migration\Schema\ModuleMigrationDescriptor;
use Vortos\Migration\Service\DependencyFactoryProviderInterface;
use Vortos\Migration\Service\MigrationDriftDetectorInterface;
use Vortos\Migration\Service\ModuleMigrationRegistryInterface;

final class MigrateVerifyCommandTest extends TestCase
{
    private DependencyFactoryProviderInterface&MockObject $factoryProvider;
    private ModuleMigrationRegistryInterface&MockObject $moduleRegistry;
    private MigrationDriftDetectorInterface&MockObject $driftDetector;
    private DependencyFactory&MockObject $factory;
    private MetadataStorage&MockObject $storage;

    protected function setUp(): void
    {
        $this->factoryProvider = $this->createMock(DependencyFactoryProviderInterface::class);
        $this->moduleRegistry  = $this->createMock(ModuleMigrationRegistryInterface::class);
        $this->driftDetector   = $this->createMock(MigrationDriftDetectorInterface::class);
        $this->factory         = $this->createMock(DependencyFactory::class);
        $this->storage         = $this->createMock(MetadataStorage::class);

        $this->factoryProvider->method('create')->willReturn($this->factory);
        $this->factory->method('getMetadataStorage')->willReturn($this->storage);
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new MigrateVerifyCommand(
            $this->factoryProvider,
            $this->moduleRegistry,
            $this->driftDetector,
        ));
    }

    private function executedList(array $versions): ExecutedMigrationsList
    {
        return new ExecutedMigrationsList(array_map(
            static fn(string $v) => new ExecutedMigration(new Version($v)),
            $versions,
        ));
    }

    private function descriptor(string $class): ModuleMigrationDescriptor
    {
        return new ModuleMigrationDescriptor(
            source: 'module',
            class: $class,
            module: 'test',
            filename: 'Version.php',
            ownership: new MigrationOwnership(tables: ['users']),
        );
    }

    public function test_no_executed_migrations_returns_success(): void
    {
        $this->storage->method('getExecutedMigrations')->willReturn($this->executedList([]));

        $tester = $this->tester();
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('No executed framework migrations to verify.', $tester->getDisplay());
    }

    public function test_clean_migration_returns_success(): void
    {
        $version = 'App\\Migrations\\Version20260506093012';
        $this->storage->method('getExecutedMigrations')->willReturn($this->executedList([$version]));
        $this->moduleRegistry->method('descriptorForClass')->willReturn($this->descriptor($version));
        $this->driftDetector->method('detect')->willReturn(
            new MigrationDriftReport(MigrationDriftReport::CompatibleExisting)
        );

        $tester = $this->tester();
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('verified', $tester->getDisplay());
        $this->assertStringContainsString($version, $tester->getDisplay());
    }

    public function test_drifted_migration_returns_failure(): void
    {
        $version = 'App\\Migrations\\Version20260506093012';
        $this->storage->method('getExecutedMigrations')->willReturn($this->executedList([$version]));
        $this->moduleRegistry->method('descriptorForClass')->willReturn($this->descriptor($version));
        $this->driftDetector->method('detect')->willReturn(
            new MigrationDriftReport(MigrationDriftReport::Partial, missingTables: ['orders'])
        );

        $tester = $this->tester();
        $tester->execute([]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('drift', $tester->getDisplay());
        $this->assertStringContainsString('orders', $tester->getDisplay());
    }

    public function test_user_migration_is_skipped(): void
    {
        $version = 'App\\Migrations\\Version20260506093012';
        $this->storage->method('getExecutedMigrations')->willReturn($this->executedList([$version]));
        $this->moduleRegistry->method('descriptorForClass')->willReturn(null);
        $this->driftDetector->expects($this->never())->method('detect');

        $tester = $this->tester();
        $tester->execute([]);

        // No module migrations → nothing to check
        $this->assertStringContainsString('No executed framework migrations to verify.', $tester->getDisplay());
        $this->assertSame(0, $tester->getStatusCode());
    }

    public function test_json_output_on_clean(): void
    {
        $version = 'App\\Migrations\\Version20260506093012';
        $this->storage->method('getExecutedMigrations')->willReturn($this->executedList([$version]));
        $this->moduleRegistry->method('descriptorForClass')->willReturn($this->descriptor($version));
        $this->driftDetector->method('detect')->willReturn(
            new MigrationDriftReport(MigrationDriftReport::CompatibleExisting)
        );

        $tester = $this->tester();
        $tester->execute(['--json' => true]);

        $data = json_decode($tester->getDisplay(), true);
        $this->assertTrue($data['ok']);
        $this->assertSame(1, $data['checked']);
        $this->assertSame(0, $data['drifted']);
        $this->assertSame(0, $tester->getStatusCode());
    }

    public function test_json_output_on_drift(): void
    {
        $version = 'App\\Migrations\\Version20260506093012';
        $this->storage->method('getExecutedMigrations')->willReturn($this->executedList([$version]));
        $this->moduleRegistry->method('descriptorForClass')->willReturn($this->descriptor($version));
        $this->driftDetector->method('detect')->willReturn(
            new MigrationDriftReport(MigrationDriftReport::Partial, missingTables: ['orders'])
        );

        $tester = $this->tester();
        $tester->execute(['--json' => true]);

        $data = json_decode($tester->getDisplay(), true);
        $this->assertFalse($data['ok']);
        $this->assertSame(1, $data['drifted']);
        $this->assertSame(1, $tester->getStatusCode());
    }
}
