<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\Pitr;

use PHPUnit\Framework\TestCase;
use Vortos\Backup\Domain\BackupKind;
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

    public function test_base_backup_script_requests_a_kind_that_actually_exists(): void
    {
        // Regression: the emitted script asked for `--kind=base`, which is not a BackupKind member,
        // so backup:run rejected it on every iteration. With `|| true` swallowing the exit code the
        // worker looked perfectly healthy and had never produced a single base backup — leaving the
        // shipped WAL segments unrestorable and PITR working only on paper.
        $script = (new ContainerizedPitrRecipe())->generate()['docker/backup/base-backup.sh'];

        self::assertMatchesRegularExpression('/--kind=(\S+)/', $script);
        preg_match('/--kind=(\S+)/', $script, $m);

        self::assertNotNull(
            BackupKind::tryFrom($m[1]),
            sprintf('Emitted --kind=%s is not a BackupKind; backup:run would reject it.', $m[1]),
        );
        self::assertSame(BackupKind::PhysicalBase, BackupKind::tryFrom($m[1]));
    }

    public function test_base_backup_failure_is_reported_rather_than_swallowed(): void
    {
        // The loop must survive a transient failure, but a permanently failing base backup must not
        // be indistinguishable from a healthy one in the container logs.
        $script = (new ContainerizedPitrRecipe())->generate()['docker/backup/base-backup.sh'];

        self::assertStringContainsString('>&2', $script, 'base-backup failures must reach stderr');
        self::assertStringContainsString('FAILED', $script);
    }

    public function test_readme_warns_that_the_environment_namespaces_the_catalog(): void
    {
        // `prod` vs `production` produces two disjoint catalogs that both look healthy until a
        // restore needs the base backup and cannot find it.
        $readme = (new ContainerizedPitrRecipe())->generate(environment: 'prod')['PITR_RECIPE.md'];

        self::assertStringContainsString('backup:list --engine=postgres --env=prod', $readme);
        self::assertStringContainsString('physical_base', $readme);
        self::assertStringContainsString('wal_segment', $readme);
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
