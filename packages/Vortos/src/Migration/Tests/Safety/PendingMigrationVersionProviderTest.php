<?php

declare(strict_types=1);

namespace Vortos\Migration\Tests\Safety;

use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Metadata\AvailableMigration;
use Doctrine\Migrations\Metadata\AvailableMigrationsList;
use Doctrine\Migrations\Metadata\AvailableMigrationsSet;
use Doctrine\Migrations\Metadata\Storage\MetadataStorage;
use Doctrine\Migrations\MigrationsRepository;
use Doctrine\Migrations\Version\MigrationStatusCalculator;
use Doctrine\Migrations\Version\Version;
use PHPUnit\Framework\TestCase;
use Vortos\Migration\Safety\PendingMigrationVersionProvider;
use Vortos\Migration\Service\DependencyFactoryProviderInterface;

/**
 * Regression for STAGE-G-1 / R7-2: getPending() previously called
 * AvailableMigrationsSet::newMigrations() — a method that does not exist — so any
 * pending migration fataled the whole `vortos:migrate:analyze` CI gate. It must now
 * compute outstanding migrations via the supported status-calculator API and never fatal.
 */
final class PendingMigrationVersionProviderTest extends TestCase
{
    public function test_getPending_returns_only_new_migrations_via_status_calculator(): void
    {
        $newList = new AvailableMigrationsList([
            $this->availableMigration('App\\Migrations\\Version20260101000000'),
            $this->availableMigration('App\\Migrations\\Version20260102000000'),
        ]);

        $calculator = $this->createMock(MigrationStatusCalculator::class);
        $calculator->expects($this->once())
            ->method('getNewMigrations')
            ->willReturn($newList);

        $provider = new PendingMigrationVersionProvider(
            $this->factoryProviderReturning($calculator, $this->availableSet([
                'App\\Migrations\\Version20250101000000',
                'App\\Migrations\\Version20260101000000',
                'App\\Migrations\\Version20260102000000',
            ])),
        );

        $this->assertSame(
            [
                'App\\Migrations\\Version20260101000000',
                'App\\Migrations\\Version20260102000000',
            ],
            $provider->getPending(),
        );
    }

    public function test_getPending_does_not_fatal_when_nothing_pending(): void
    {
        $calculator = $this->createMock(MigrationStatusCalculator::class);
        $calculator->method('getNewMigrations')->willReturn(new AvailableMigrationsList([]));

        $provider = new PendingMigrationVersionProvider(
            $this->factoryProviderReturning($calculator, $this->availableSet([])),
        );

        $this->assertSame([], $provider->getPending());
    }

    public function test_getAll_returns_every_available_migration(): void
    {
        $calculator = $this->createMock(MigrationStatusCalculator::class);

        $provider = new PendingMigrationVersionProvider(
            $this->factoryProviderReturning($calculator, $this->availableSet([
                'App\\Migrations\\Version20250101000000',
                'App\\Migrations\\Version20260101000000',
            ])),
        );

        $this->assertSame(
            [
                'App\\Migrations\\Version20250101000000',
                'App\\Migrations\\Version20260101000000',
            ],
            $provider->getAll(),
        );
    }

    private function factoryProviderReturning(
        MigrationStatusCalculator $calculator,
        AvailableMigrationsSet $available,
    ): DependencyFactoryProviderInterface {
        $repository = $this->createMock(MigrationsRepository::class);
        $repository->method('getMigrations')->willReturn($available);

        $factory = $this->createMock(DependencyFactory::class);
        $factory->method('getMigrationStatusCalculator')->willReturn($calculator);
        $factory->method('getMigrationRepository')->willReturn($repository);
        $factory->method('getMetadataStorage')->willReturn($this->createMock(MetadataStorage::class));

        $factoryProvider = $this->createMock(DependencyFactoryProviderInterface::class);
        $factoryProvider->method('create')->willReturn($factory);

        return $factoryProvider;
    }

    /** @param list<string> $versions */
    private function availableSet(array $versions): AvailableMigrationsSet
    {
        return new AvailableMigrationsSet(
            array_map($this->availableMigration(...), $versions),
        );
    }

    private function availableMigration(string $version): AvailableMigration
    {
        return new AvailableMigration(
            new Version($version),
            $this->createMock(AbstractMigration::class),
        );
    }
}
