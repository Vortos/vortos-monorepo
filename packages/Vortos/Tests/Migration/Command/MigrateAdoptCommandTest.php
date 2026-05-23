<?php

declare(strict_types=1);

namespace Vortos\Tests\Migration\Command;

use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Metadata\AvailableMigration;
use Doctrine\Migrations\Metadata\AvailableMigrationsList;
use Doctrine\Migrations\Metadata\ExecutedMigration;
use Doctrine\Migrations\Metadata\ExecutedMigrationsList;
use Doctrine\Migrations\Metadata\Storage\MetadataStorage;
use Doctrine\Migrations\Version\MigrationPlanCalculator;
use Doctrine\Migrations\Version\Version;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\Migration\Command\MigrateAdoptCommand;
use Vortos\Migration\Schema\MigrationDriftReport;
use Vortos\Migration\Schema\MigrationOwnership;
use Vortos\Migration\Schema\ModuleMigrationDescriptor;
use Vortos\Migration\Service\DependencyFactoryProviderInterface;
use Vortos\Migration\Service\MigrationDriftDetectorInterface;
use Vortos\Migration\Service\MigrationDriftFormatterInterface;
use Vortos\Migration\Service\ModuleMigrationRegistryInterface;
use Vortos\Migration\Service\UserMigrationOwnershipExtractorInterface;

final class MigrateAdoptCommandTest extends TestCase
{
    private DependencyFactoryProviderInterface&MockObject $factoryProvider;
    private ModuleMigrationRegistryInterface&MockObject $moduleRegistry;
    private MigrationDriftDetectorInterface&MockObject $driftDetector;
    private MigrationDriftFormatterInterface&MockObject $driftFormatter;
    private UserMigrationOwnershipExtractorInterface&MockObject $ownershipExtractor;
    private DependencyFactory&MockObject $factory;
    private MetadataStorage&MockObject $storage;
    private MigrationPlanCalculator&MockObject $calculator;

    protected function setUp(): void
    {
        $this->factoryProvider    = $this->createMock(DependencyFactoryProviderInterface::class);
        $this->moduleRegistry     = $this->createMock(ModuleMigrationRegistryInterface::class);
        $this->driftDetector      = $this->createMock(MigrationDriftDetectorInterface::class);
        $this->driftFormatter     = $this->createMock(MigrationDriftFormatterInterface::class);
        $this->ownershipExtractor = $this->createMock(UserMigrationOwnershipExtractorInterface::class);
        $this->factory            = $this->createMock(DependencyFactory::class);
        $this->storage            = $this->createMock(MetadataStorage::class);
        $this->calculator         = $this->createMock(MigrationPlanCalculator::class);

        $this->factoryProvider->method('create')->willReturn($this->factory);
        $this->factory->method('getMetadataStorage')->willReturn($this->storage);
        $this->factory->method('getMigrationPlanCalculator')->willReturn($this->calculator);
        $this->driftFormatter->method('label')->willReturn('compatible_existing');
        $this->driftFormatter->method('toArray')->willReturn([]);
        $this->ownershipExtractor->method('extract')->willReturn(null);
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new MigrateAdoptCommand(
            $this->factoryProvider,
            $this->moduleRegistry,
            $this->driftDetector,
            $this->driftFormatter,
            $this->ownershipExtractor,
        ));
    }

    private function availableMigration(string $version): AvailableMigration
    {
        $migration = $this->createMock(AbstractMigration::class);
        return new AvailableMigration(new Version($version), $migration);
    }

    private function descriptor(string $version): ModuleMigrationDescriptor
    {
        return new ModuleMigrationDescriptor(
            source: 'stub',
            class: $version,
            module: 'TestModule',
            filename: 'test.sql',
            ownership: new MigrationOwnership(['test_table'], []),
        );
    }

    private function report(string $status): MigrationDriftReport
    {
        return new MigrationDriftReport(
            status: $status,
            existingTables: $status === MigrationDriftReport::CompatibleExisting ? ['test_table'] : [],
            missingTables: $status === MigrationDriftReport::Clean ? ['test_table'] : [],
            existingIndexes: [],
            missingIndexes: [],
            missingColumns: [],
        );
    }

    public function test_includes_user_migrations_by_default(): void
    {
        $userVersion = 'App\\Migrations\\Version20260506093012';

        $this->calculator->method('getMigrations')->willReturn(
            new AvailableMigrationsList([$this->availableMigration($userVersion)])
        );
        $this->storage->method('getExecutedMigrations')->willReturn(new ExecutedMigrationsList([]));
        $this->moduleRegistry->method('descriptorsByClass')->willReturn([]);

        $tester = $this->tester();
        $tester->execute(['--all-compatible' => true, '--dry-run' => true], ['interactive' => false]);

        $this->assertStringContainsString($userVersion, $tester->getDisplay());
    }

    public function test_module_only_skips_user_migrations(): void
    {
        $userVersion = 'App\\Migrations\\Version20260506093012';

        $this->calculator->method('getMigrations')->willReturn(
            new AvailableMigrationsList([$this->availableMigration($userVersion)])
        );
        $this->storage->method('getExecutedMigrations')->willReturn(new ExecutedMigrationsList([]));
        $this->moduleRegistry->method('descriptorsByClass')->willReturn([]);

        $tester = $this->tester();
        $tester->execute(['--all-compatible' => true, '--module-only' => true], ['interactive' => false]);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('No pending migration matched', $output);
    }

    public function test_blocks_user_migrations_without_allow_unverified(): void
    {
        $userVersion = 'App\\Migrations\\Version20260506093012';

        $this->calculator->method('getMigrations')->willReturn(
            new AvailableMigrationsList([$this->availableMigration($userVersion)])
        );
        $this->storage->method('getExecutedMigrations')->willReturn(new ExecutedMigrationsList([]));
        $this->moduleRegistry->method('descriptorsByClass')->willReturn([]);

        $tester = $this->tester();
        $tester->execute(['--all-compatible' => true], ['interactive' => false]);

        $this->assertSame(1, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('--allow-unverified', $output);
        $this->assertStringContainsString('Manually confirm', $output);
    }

    public function test_guided_output_shows_adopt_command_when_blocked(): void
    {
        $userVersion = 'App\\Migrations\\Version20260506093012';

        $this->calculator->method('getMigrations')->willReturn(
            new AvailableMigrationsList([$this->availableMigration($userVersion)])
        );
        $this->storage->method('getExecutedMigrations')->willReturn(new ExecutedMigrationsList([]));
        $this->moduleRegistry->method('descriptorsByClass')->willReturn([]);

        $tester = $this->tester();
        $tester->execute(['--all-compatible' => true], ['interactive' => false]);

        $this->assertStringContainsString('php vortos migrate:adopt', $tester->getDisplay());
    }

    public function test_adopts_user_migrations_with_allow_unverified(): void
    {
        $userVersion = 'App\\Migrations\\Version20260506093012';

        $this->calculator->method('getMigrations')->willReturn(
            new AvailableMigrationsList([$this->availableMigration($userVersion)])
        );
        $this->storage->method('getExecutedMigrations')->willReturn(new ExecutedMigrationsList([]));
        $this->moduleRegistry->method('descriptorsByClass')->willReturn([]);
        $this->storage->expects($this->once())->method('complete');

        $tester = $this->tester();
        $tester->execute(['--all-compatible' => true, '--allow-unverified' => true], ['interactive' => false]);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('unverified', $output);
    }

    public function test_recovery_hint_shown_after_adopting_unverified(): void
    {
        $userVersion = 'App\\Migrations\\Version20260506093012';

        $this->calculator->method('getMigrations')->willReturn(
            new AvailableMigrationsList([$this->availableMigration($userVersion)])
        );
        $this->storage->method('getExecutedMigrations')->willReturn(new ExecutedMigrationsList([]));
        $this->moduleRegistry->method('descriptorsByClass')->willReturn([]);

        $tester = $this->tester();
        $tester->execute(['--all-compatible' => true, '--allow-unverified' => true], ['interactive' => false]);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('migrate:unadopt', $output);
        $this->assertStringContainsString($userVersion, $output);
    }

    public function test_framework_migration_compatible_is_adopted(): void
    {
        $fwVersion = 'App\\Migrations\\Version20260501000000';
        $descriptor = $this->descriptor($fwVersion);
        $report = $this->report(MigrationDriftReport::CompatibleExisting);

        $this->calculator->method('getMigrations')->willReturn(
            new AvailableMigrationsList([$this->availableMigration($fwVersion)])
        );
        $this->storage->method('getExecutedMigrations')->willReturn(new ExecutedMigrationsList([]));
        $this->moduleRegistry->method('descriptorsByClass')->willReturn([$fwVersion => $descriptor]);
        $this->driftDetector->method('detect')->willReturn($report);
        $this->storage->expects($this->once())->method('complete');

        $tester = $this->tester();
        $tester->execute(['--all-compatible' => true], ['interactive' => false]);

        $this->assertSame(0, $tester->getStatusCode());
    }

    public function test_framework_partial_migration_blocks_all(): void
    {
        $fwVersion = 'App\\Migrations\\Version20260501000000';
        $descriptor = $this->descriptor($fwVersion);
        $report = $this->report(MigrationDriftReport::Partial);

        $this->calculator->method('getMigrations')->willReturn(
            new AvailableMigrationsList([$this->availableMigration($fwVersion)])
        );
        $this->storage->method('getExecutedMigrations')->willReturn(new ExecutedMigrationsList([]));
        $this->moduleRegistry->method('descriptorsByClass')->willReturn([$fwVersion => $descriptor]);
        $this->driftDetector->method('detect')->willReturn($report);
        $this->storage->expects($this->never())->method('complete');

        $tester = $this->tester();
        $tester->execute(['--all-compatible' => true], ['interactive' => false]);

        $this->assertSame(1, $tester->getStatusCode());
    }

    public function test_dry_run_does_not_record(): void
    {
        $userVersion = 'App\\Migrations\\Version20260506093012';

        $this->calculator->method('getMigrations')->willReturn(
            new AvailableMigrationsList([$this->availableMigration($userVersion)])
        );
        $this->storage->method('getExecutedMigrations')->willReturn(new ExecutedMigrationsList([]));
        $this->moduleRegistry->method('descriptorsByClass')->willReturn([]);
        $this->storage->expects($this->never())->method('complete');

        $tester = $this->tester();
        $tester->execute(['--all-compatible' => true, '--dry-run' => true, '--allow-unverified' => true], ['interactive' => false]);

        $this->assertSame(0, $tester->getStatusCode());
    }

    public function test_json_output_includes_user_unverified_key(): void
    {
        $userVersion = 'App\\Migrations\\Version20260506093012';

        $this->calculator->method('getMigrations')->willReturn(
            new AvailableMigrationsList([$this->availableMigration($userVersion)])
        );
        $this->storage->method('getExecutedMigrations')->willReturn(new ExecutedMigrationsList([]));
        $this->moduleRegistry->method('descriptorsByClass')->willReturn([]);

        $tester = $this->tester();
        $tester->execute(['--all-compatible' => true, '--json' => true, '--allow-unverified' => true], ['interactive' => false]);

        $data = json_decode($tester->getDisplay(), true);
        $this->assertArrayHasKey('user_unverified', $data);
        $this->assertContains($userVersion, $data['user_unverified']);
    }

    public function test_skips_already_executed_migrations(): void
    {
        $version = 'App\\Migrations\\Version20260506093012';

        $this->calculator->method('getMigrations')->willReturn(
            new AvailableMigrationsList([$this->availableMigration($version)])
        );
        $this->storage->method('getExecutedMigrations')->willReturn(
            new ExecutedMigrationsList([new ExecutedMigration(new Version($version))])
        );
        $this->moduleRegistry->method('descriptorsByClass')->willReturn([]);

        $tester = $this->tester();
        $tester->execute(['--all-compatible' => true], ['interactive' => false]);

        $this->assertStringContainsString('No pending migration matched', $tester->getDisplay());
    }
}
