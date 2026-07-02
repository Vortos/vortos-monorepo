<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Backup\Console\BackupDrillCommand;
use Vortos\Backup\DependencyInjection\BackupExtension;
use Vortos\Backup\Drill\DrillRunner;

/**
 * D7: the restore-drill runner needs an ephemeral-database provisioner that only binds when
 * VORTOS_BACKUP_DRILL_DSN is configured. Registering it unconditionally left the port unbound
 * and broke every console/worker boot on a stock backup install. The runner is now gated on the
 * DSN while the command stays visible and fails loudly when the DSN is absent.
 */
final class BackupDrillGatingTest extends TestCase
{
    public function test_drill_runner_absent_without_dsn_but_command_present(): void
    {
        $prev = $_ENV['VORTOS_BACKUP_DRILL_DSN'] ?? null;
        unset($_ENV['VORTOS_BACKUP_DRILL_DSN']);

        try {
            $container = $this->load();

            self::assertFalse(
                $container->hasDefinition(DrillRunner::class),
                'DrillRunner must not register without a drill DSN (its provisioner port is unbound).',
            );
            self::assertTrue(
                $container->hasDefinition(BackupDrillCommand::class),
                'backup:drill command stays registered and fails loudly when the DSN is absent.',
            );
        } finally {
            if ($prev !== null) { $_ENV['VORTOS_BACKUP_DRILL_DSN'] = $prev; }
        }
    }

    public function test_drill_runner_registers_with_dsn(): void
    {
        $prev = $_ENV['VORTOS_BACKUP_DRILL_DSN'] ?? null;
        $_ENV['VORTOS_BACKUP_DRILL_DSN'] = 'pgsql://drill@localhost:5432/drill';

        try {
            $container = $this->load();

            self::assertTrue(
                $container->hasDefinition(DrillRunner::class),
                'DrillRunner must register when a drill DSN is configured.',
            );
        } finally {
            if ($prev !== null) { $_ENV['VORTOS_BACKUP_DRILL_DSN'] = $prev; } else { unset($_ENV['VORTOS_BACKUP_DRILL_DSN']); }
        }
    }

    private function load(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', sys_get_temp_dir() . '/vortos_backup_test');
        $container->setParameter('kernel.env', 'test');
        (new BackupExtension())->load([], $container);

        return $container;
    }
}
