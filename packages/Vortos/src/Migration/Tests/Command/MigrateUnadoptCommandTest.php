<?php

declare(strict_types=1);

namespace Vortos\Migration\Tests\Command;

use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Metadata\ExecutedMigration;
use Doctrine\Migrations\Metadata\ExecutedMigrationsList;
use Doctrine\Migrations\Metadata\Storage\MetadataStorage;
use Doctrine\Migrations\Version\Direction;
use Doctrine\Migrations\Version\ExecutionResult;
use Doctrine\Migrations\Version\Version;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\Migration\Command\MigrateUnadoptCommand;
use Vortos\Migration\Schema\ModuleMigrationDescriptor;
use Vortos\Migration\Schema\MigrationOwnership;
use Vortos\Migration\Service\DependencyFactoryProviderInterface;
use Vortos\Migration\Service\ModuleMigrationRegistryInterface;

final class MigrateUnadoptCommandTest extends TestCase
{
    private DependencyFactoryProviderInterface&MockObject $factoryProvider;
    private ModuleMigrationRegistryInterface&MockObject $moduleRegistry;
    private DependencyFactory&MockObject $factory;
    private MetadataStorage&MockObject $storage;

    protected function setUp(): void
    {
        $this->factoryProvider = $this->createMock(DependencyFactoryProviderInterface::class);
        $this->moduleRegistry  = $this->createMock(ModuleMigrationRegistryInterface::class);
        $this->factory         = $this->createMock(DependencyFactory::class);
        $this->storage         = $this->createMock(MetadataStorage::class);

        $this->factoryProvider->method('create')->willReturn($this->factory);
        $this->factory->method('getMetadataStorage')->willReturn($this->storage);
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new MigrateUnadoptCommand($this->factoryProvider, $this->moduleRegistry));
    }

    private function executedList(array $versions): ExecutedMigrationsList
    {
        $items = array_map(
            static fn(string $v) => new ExecutedMigration(new Version($v)),
            $versions,
        );
        return new ExecutedMigrationsList($items);
    }

    public function test_unadopts_specific_version(): void
    {
        $version = 'App\\Migrations\\Version20260506093012';
        $this->storage->method('getExecutedMigrations')->willReturn($this->executedList([$version]));
        $this->moduleRegistry->method('descriptorForClass')->willReturn(null);

        $this->storage->expects($this->once())
            ->method('complete')
            ->with($this->callback(static fn(ExecutionResult $r) =>
                (string) $r->getVersion() === $version && $r->getDirection() === Direction::DOWN
            ));

        $tester = $this->tester();
        $tester->execute(['version' => $version], ['interactive' => false]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString($version, $tester->getDisplay());
        $this->assertStringContainsString('Unadopted', $tester->getDisplay());
    }

    public function test_unadopts_latest_when_no_version_given(): void
    {
        $latest = 'App\\Migrations\\Version20260507000000';
        $this->storage->method('getExecutedMigrations')->willReturn($this->executedList([
            'App\\Migrations\\Version20260506000000',
            $latest,
        ]));
        $this->moduleRegistry->method('descriptorForClass')->willReturn(null);

        $this->storage->expects($this->once())
            ->method('complete')
            ->with($this->callback(static fn(ExecutionResult $r) =>
                (string) $r->getVersion() === $latest && $r->getDirection() === Direction::DOWN
            ));

        $tester = $this->tester();
        $tester->execute([], ['interactive' => false]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString($latest, $tester->getDisplay());
    }

    public function test_fails_when_version_not_executed(): void
    {
        $this->storage->method('getExecutedMigrations')->willReturn($this->executedList([
            'App\\Migrations\\Version20260506093012',
        ]));

        $this->storage->expects($this->never())->method('complete');

        $tester = $this->tester();
        $tester->execute(['version' => 'Version20260509000000'], ['interactive' => false]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('not recorded as executed', $tester->getDisplay());
    }

    public function test_fails_when_no_executed_migrations_exist(): void
    {
        $this->storage->method('getExecutedMigrations')->willReturn($this->executedList([]));
        $this->storage->expects($this->never())->method('complete');

        $tester = $this->tester();
        $tester->execute([], ['interactive' => false]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('No executed migrations', $tester->getDisplay());
    }

    public function test_warns_for_framework_migration(): void
    {
        $version = 'App\\Migrations\\Version20260506093012';
        $this->storage->method('getExecutedMigrations')->willReturn($this->executedList([$version]));

        $descriptor = new ModuleMigrationDescriptor(
            source: 'stub',
            class: $version,
            module: 'Messaging',
            filename: '001_outbox.sql',
            ownership: new MigrationOwnership(['vortos_outbox'], []),
        );
        $this->moduleRegistry->method('descriptorForClass')->willReturn($descriptor);

        $tester = $this->tester();
        $tester->execute(['version' => $version], ['interactive' => false]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('framework module migration', $tester->getDisplay());
    }

    public function test_force_skips_prompt(): void
    {
        $version = 'App\\Migrations\\Version20260506093012';
        $this->storage->method('getExecutedMigrations')->willReturn($this->executedList([$version]));
        $this->moduleRegistry->method('descriptorForClass')->willReturn(null);
        $this->storage->expects($this->once())->method('complete');

        $tester = $this->tester();
        $tester->execute(['version' => $version, '--force' => true], ['interactive' => true]);

        $this->assertSame(0, $tester->getStatusCode());
    }

    public function test_shows_next_steps_in_output(): void
    {
        $version = 'App\\Migrations\\Version20260506093012';
        $this->storage->method('getExecutedMigrations')->willReturn($this->executedList([$version]));
        $this->moduleRegistry->method('descriptorForClass')->willReturn(null);

        $tester = $this->tester();
        $tester->execute(['version' => $version], ['interactive' => false]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('php vortos migrate', $display);
        $this->assertStringContainsString('migrate:adopt', $display);
    }

    public function test_resolves_short_version_name(): void
    {
        $full = 'App\\Migrations\\Version20260506093012';
        $this->storage->method('getExecutedMigrations')->willReturn($this->executedList([$full]));
        $this->moduleRegistry->method('descriptorForClass')->willReturn(null);
        $this->storage->expects($this->once())->method('complete');

        $tester = $this->tester();
        $tester->execute(['version' => 'Version20260506093012'], ['interactive' => false]);

        $this->assertSame(0, $tester->getStatusCode());
    }
}
