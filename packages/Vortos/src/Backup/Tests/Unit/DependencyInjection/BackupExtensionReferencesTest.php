<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Backup\DependencyInjection\BackupExtension;

/**
 * Every reference this extension makes to its OWN namespace must resolve.
 *
 * The gap this closes let a crash-loop reach production. The existing wiring tests assert that
 * definitions EXIST; none of them resolves a reference, so a service wired to
 * `Vortos\Backup\Catalog\WalVolumeReadModelInterface` — an interface that is implemented but never
 * registered under that id — passed every test and every deploy gate, then took the backup sidecar
 * into a boot loop. WAL shipping stopped with it.
 *
 * It got that far because the broken wiring only exists in one configuration: the point-in-time
 * drill stack registers only when VORTOS_BACKUP_DRILL_DOCKER_HOST is set, which is true on the
 * backup node and nowhere else. The app deployed green while the one host that would compile that
 * branch could not start. So this test compiles BOTH shapes.
 *
 * References declared optional — NULL_ON_INVALID_REFERENCE, as DrillRunner's consumers do — are
 * exempt: absence is their documented contract rather than a mistake.
 *
 * Scoped to `Vortos\Backup\*` deliberately. References to Doctrine, PSR interfaces and other
 * packages are satisfied by the application container, so asserting them here would only produce a
 * list of exceptions to maintain. The extension owns its own namespace, and a dangling reference
 * into it is always a bug.
 */
final class BackupExtensionReferencesTest extends TestCase
{
    public function testEveryBackupNamespaceReferenceResolvesWithoutTheDrillStack(): void
    {
        $this->assertReferencesResolve($this->load(drillDockerHost: null));
    }

    /**
     * The configuration the backup sidecar actually runs, and the one nothing else compiles.
     */
    public function testEveryBackupNamespaceReferenceResolvesWithTheContainerDrillStack(): void
    {
        $container = $this->load(drillDockerHost: 'tcp://docker-socket-proxy:2375');

        // Guard the guard: if the PITR services stopped registering, the assertions below would
        // pass by having nothing to check.
        self::assertTrue(
            $container->hasDefinition(\Vortos\Backup\Pitr\WalArchiveFeeder::class),
            'the point-in-time stack must register in container-drill mode',
        );
        self::assertTrue(
            $container->hasDefinition(\Vortos\Backup\Restore\Driver\Postgres\PostgresPitrRestoreTarget::class),
        );
        self::assertTrue(
            $container->hasDefinition(\Vortos\Backup\Drill\Driver\Postgres\RecoveringPostgresProvisioner::class),
        );

        $this->assertReferencesResolve($container);
    }

    private function assertReferencesResolve(ContainerBuilder $container): void
    {
        $unresolved = [];

        foreach ($container->getDefinitions() as $serviceId => $definition) {
            foreach ($this->referencesOf($definition) as $reference) {
                $target = (string) $reference;

                if (!str_starts_with($target, 'Vortos\\Backup\\')) {
                    continue;
                }

                // Optional by construction. DrillRunner, for one, is deliberately absent unless a
                // drill mode is configured, and its consumers declare that by asking for null
                // rather than by being wrong.
                if ($reference->getInvalidBehavior() !== ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE) {
                    continue;
                }

                if ($container->hasDefinition($target) || $container->hasAlias($target)) {
                    continue;
                }

                $unresolved[] = sprintf('%s -> %s', $serviceId, $target);
            }
        }

        self::assertSame([], $unresolved, "Unresolvable references:\n" . implode("\n", $unresolved));
    }

    /**
     * @return list<Reference>
     */
    private function referencesOf(Definition $definition): array
    {
        $found = [];

        $walk = static function (mixed $value) use (&$walk, &$found): void {
            if ($value instanceof Reference) {
                $found[] = $value;

                return;
            }

            if (\is_array($value)) {
                foreach ($value as $item) {
                    $walk($item);
                }
            }
        };

        $walk($definition->getArguments());
        $walk($definition->getFactory());
        $walk($definition->getMethodCalls());
        $walk($definition->getProperties());

        return $found;
    }

    private function load(?string $drillDockerHost): ContainerBuilder
    {
        $previous = $_ENV['VORTOS_BACKUP_DRILL_DOCKER_HOST'] ?? null;

        if ($drillDockerHost === null) {
            unset($_ENV['VORTOS_BACKUP_DRILL_DOCKER_HOST']);
        } else {
            $_ENV['VORTOS_BACKUP_DRILL_DOCKER_HOST'] = $drillDockerHost;
        }

        try {
            $container = new ContainerBuilder();
            $container->setParameter('kernel.project_dir', sys_get_temp_dir() . '/vortos_backup_test');
            $container->setParameter('kernel.env', 'test');
            (new BackupExtension())->load([], $container);

            return $container;
        } finally {
            if ($previous !== null) {
                $_ENV['VORTOS_BACKUP_DRILL_DOCKER_HOST'] = $previous;
            } else {
                unset($_ENV['VORTOS_BACKUP_DRILL_DOCKER_HOST']);
            }
        }
    }
}
