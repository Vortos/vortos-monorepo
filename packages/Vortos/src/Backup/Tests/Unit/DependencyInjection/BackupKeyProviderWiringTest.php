<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Backup\DependencyInjection\BackupExtension;
use Vortos\Backup\Drill\DrillRunner;
use Vortos\Backup\Restore\RestoreCoordinator;
use Vortos\Secrets\Key\KeyProviderInterface;

/**
 * BK-13: the write path encrypted with the dedicated backup keypair while the read path resolved
 * the generic KeyProviderInterface — the box's secrets-store identity, a different key. The drill
 * therefore reported "Backup undecryptable: no key provider configured" even on a host holding the
 * correct identity, because it was looking for a key that never wrote anything.
 *
 * Both consumers of the read path must resolve the backup provider whenever it exists.
 */
final class BackupKeyProviderWiringTest extends TestCase
{
    private const BACKUP_KEY_SERVICE = 'vortos.backup.key_provider';

    public function test_restore_and_drill_resolve_the_backup_provider_when_a_backup_keypair_is_configured(): void
    {
        $container = $this->withEnv([
            'VORTOS_BACKUP_AGE_PUBLIC_KEY' => 'age1testonlypublickeyvaluenotusedtoencryptanything00000000',
            'VORTOS_BACKUP_DRILL_DSN' => 'pgsql://drill@localhost:5432/drill',
        ]);

        self::assertTrue(
            $container->hasDefinition(self::BACKUP_KEY_SERVICE),
            'A configured backup public key must register the dedicated provider.',
        );

        foreach ([RestoreCoordinator::class, DrillRunner::class] as $consumer) {
            $keyProvider = $container->getDefinition($consumer)->getArgument('$keyProvider');

            self::assertInstanceOf(Reference::class, $keyProvider);
            self::assertSame(
                self::BACKUP_KEY_SERVICE,
                (string) $keyProvider,
                $consumer . ' must read through the backup keypair, not the secrets-store identity.',
            );
        }
    }

    public function test_both_fall_back_to_the_generic_provider_without_a_backup_keypair(): void
    {
        $container = $this->withEnv([
            'VORTOS_BACKUP_AGE_PUBLIC_KEY' => null,
            'VORTOS_BACKUP_DRILL_DSN' => 'pgsql://drill@localhost:5432/drill',
        ]);

        self::assertFalse($container->hasDefinition(self::BACKUP_KEY_SERVICE));

        foreach ([RestoreCoordinator::class, DrillRunner::class] as $consumer) {
            $keyProvider = $container->getDefinition($consumer)->getArgument('$keyProvider');

            self::assertInstanceOf(Reference::class, $keyProvider);
            self::assertSame(KeyProviderInterface::class, (string) $keyProvider);
        }
    }

    /**
     * @param array<string, string|null> $env null unsets
     */
    private function withEnv(array $env): ContainerBuilder
    {
        $previous = [];
        foreach ($env as $name => $value) {
            $previous[$name] = $_ENV[$name] ?? null;
            if ($value === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $value;
            }
        }

        try {
            $container = new ContainerBuilder();
            $container->setParameter('kernel.project_dir', sys_get_temp_dir() . '/vortos_backup_test');
            $container->setParameter('kernel.env', 'test');
            (new BackupExtension())->load([], $container);

            return $container;
        } finally {
            foreach ($previous as $name => $value) {
                if ($value === null) {
                    unset($_ENV[$name]);
                } else {
                    $_ENV[$name] = $value;
                }
            }
        }
    }
}
