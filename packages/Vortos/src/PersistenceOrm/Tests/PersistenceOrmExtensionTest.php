<?php

declare(strict_types=1);

namespace Vortos\PersistenceOrm\Tests;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Proxy\ProxyFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\PersistenceOrm\DependencyInjection\PersistenceOrmExtension;
use Vortos\PersistenceOrm\Factory\EntityManagerFactory;

final class PersistenceOrmExtensionTest extends TestCase
{
    public function test_allows_empty_dsn_so_setup_can_bootstrap_env_file(): void
    {
        $container = $this->container('');

        (new PersistenceOrmExtension())->load([], $container);

        $this->assertSame('', $container->getDefinition(EntityManager::class)->getArgument(0));
    }

    public function test_entity_manager_factory_requires_vortos_write_db_dsn_at_runtime(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('VORTOS_WRITE_DB_DSN');

        EntityManagerFactory::fromDsn('', [sys_get_temp_dir()]);
    }

    public function test_production_proxies_are_generated_on_first_miss_not_never(): void
    {
        // Regression: ORMSetup defaults prod ($devMode === false) to
        // AUTOGENERATE_NEVER, so Doctrine require()s proxy files that nothing
        // ever writes → fatal `require(__CG__...php): Failed to open stream`
        // on the first lazy reference. We must self-heal the first miss.
        $em = EntityManagerFactory::fromDsn(
            'sqlite:///:memory:',
            [sys_get_temp_dir()],
            devMode: false,
        );

        $this->assertSame(
            ProxyFactory::AUTOGENERATE_FILE_NOT_EXISTS,
            $em->getConfiguration()->getAutoGenerateProxyClasses(),
        );
    }

    public function test_dev_mode_always_regenerates_proxies(): void
    {
        $em = EntityManagerFactory::fromDsn(
            'sqlite:///:memory:',
            [sys_get_temp_dir()],
            devMode: true,
        );

        // ORMSetup maps isDevMode=true → true → AUTOGENERATE_ALWAYS; the
        // production override must not touch dev behaviour.
        $this->assertSame(
            ProxyFactory::AUTOGENERATE_ALWAYS,
            $em->getConfiguration()->getAutoGenerateProxyClasses(),
        );
    }

    public function test_uses_vortos_write_db_dsn_parameter_for_entity_manager_factory(): void
    {
        $container = $this->container('pgsql://postgres:secret@write_db:5432/demo');

        (new PersistenceOrmExtension())->load([], $container);

        $this->assertSame(
            'pgsql://postgres:secret@write_db:5432/demo',
            $container->getDefinition(EntityManager::class)->getArgument(0),
        );
    }

    private function container(string $dsn): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());
        $container->setParameter('kernel.env', 'test');
        $container->setParameter('vortos.persistence.write_dsn', $dsn);

        return $container;
    }
}
