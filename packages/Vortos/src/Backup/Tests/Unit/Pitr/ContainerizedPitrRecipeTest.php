<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\Pitr;

use PHPUnit\Framework\TestCase;
use Vortos\Backup\Pitr\ContainerizedPitrRecipe;

/**
 * P3-1: the containerized PITR recipe must archive WAL without invoking PHP in the Postgres image
 * (a pure cp archive_command) and ship it off-host from a worker in the app image.
 */
final class ContainerizedPitrRecipeTest extends TestCase
{
    public function test_recipe_emits_php_free_archive_command_and_shipper(): void
    {
        $artifacts = (new ContainerizedPitrRecipe())->generate(walVolume: '/wal_archive', environment: 'prod');

        self::assertArrayHasKey('docker/postgres/postgresql.conf', $artifacts);
        self::assertArrayHasKey('docker-compose.pitr.yaml', $artifacts);
        self::assertArrayHasKey('docker/backup/wal-shipper.sh', $artifacts);

        $conf = $artifacts['docker/postgres/postgresql.conf'];
        self::assertStringContainsString('archive_mode = on', $conf);
        self::assertStringContainsString('cp %p /wal_archive/%f', $conf);
        // The archive_command must NOT shell out to the Vortos CLI in the Postgres image.
        self::assertStringNotContainsString('backup:wal-archive', $conf);

        // The shipper (app image) is where the CLI ships segments off-host.
        self::assertStringContainsString('vortos:backup:wal-archive', $artifacts['docker/backup/wal-shipper.sh']);
        self::assertStringContainsString('wal_archive:/wal_archive', $artifacts['docker-compose.pitr.yaml']);
    }

    public function test_paths_and_services_are_configurable(): void
    {
        $artifacts = (new ContainerizedPitrRecipe())->generate(
            walVolume: '/var/wal',
            backendService: 'app',
            postgresService: 'db',
        );

        $compose = $artifacts['docker-compose.pitr.yaml'];
        self::assertStringContainsString('wal_archive:/var/wal', $compose);
        self::assertStringContainsString('service: app', $compose);
        self::assertMatchesRegularExpression('/^\s{2}db:/m', $compose);
    }
}
