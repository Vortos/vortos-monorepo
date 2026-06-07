<?php

declare(strict_types=1);

namespace Vortos\PersistenceOrm\Tests;

use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Cache\Contract\TaggedCacheInterface;
use Vortos\PersistenceOrm\Cache\OrmMetadataCache;
use Vortos\PersistenceOrm\Command\OrmClearCacheCommand;
use Vortos\PersistenceOrm\DependencyInjection\Compiler\OrmMetadataCachePass;
use Vortos\PersistenceOrm\Transaction\OrmUnitOfWork;

final class OrmMetadataCachePassTest extends TestCase
{
    private function container(bool $hasCache = false, string $env = 'prod'): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.env', $env);
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());

        // Minimal EM definition with 4 args: dsn, paths, devMode, metadataCache=null
        $emDef = new Definition(EntityManager::class);
        $emDef->setArguments(['dsn', [], false, null]);
        $container->setDefinition(EntityManager::class, $emDef);

        // UnitOfWork is used to detect PersistenceOrmExtension was loaded
        $container->setDefinition(OrmUnitOfWork::class, new Definition(OrmUnitOfWork::class));

        if ($hasCache) {
            $container->setDefinition(TaggedCacheInterface::class, new Definition(TaggedCacheInterface::class));
        }

        return $container;
    }

    public function test_does_nothing_when_orm_not_loaded(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.env', 'prod');

        // No OrmUnitOfWork — pass should be a no-op
        (new OrmMetadataCachePass())->process($container);

        $this->assertFalse($container->hasDefinition(OrmMetadataCache::class));
    }

    public function test_wires_cache_when_tagged_cache_available(): void
    {
        $container = $this->container(hasCache: true);

        (new OrmMetadataCachePass())->process($container);

        $this->assertTrue($container->hasDefinition(OrmMetadataCache::class));

        $arg3 = $container->getDefinition(EntityManager::class)->getArgument(3);
        $this->assertInstanceOf(Reference::class, $arg3);
        $this->assertSame(OrmMetadataCache::class, (string) $arg3);
    }

    public function test_registers_orm_clear_cache_command_when_cache_available(): void
    {
        $container = $this->container(hasCache: true);

        (new OrmMetadataCachePass())->process($container);

        $this->assertTrue($container->hasDefinition(OrmClearCacheCommand::class));
    }

    public function test_does_not_wire_cache_when_not_available(): void
    {
        $container = $this->container(hasCache: false);

        (new OrmMetadataCachePass())->process($container);

        $this->assertFalse($container->hasDefinition(OrmMetadataCache::class));

        // Arg 3 should remain null
        $this->assertNull($container->getDefinition(EntityManager::class)->getArgument(3));
    }

    public function test_does_not_register_clear_command_when_cache_absent(): void
    {
        $container = $this->container(hasCache: false);

        (new OrmMetadataCachePass())->process($container);

        $this->assertFalse($container->hasDefinition(OrmClearCacheCommand::class));
    }

    public function test_does_not_override_existing_alias(): void
    {
        $container = $this->container(hasCache: false);

        // Simulate a user who registered their own cache implementation
        $container->setDefinition(TaggedCacheInterface::class, new Definition('MyOwnCache'));
        $container->setDefinition(OrmMetadataCache::class, new Definition(OrmMetadataCache::class));
        $container->getDefinition(EntityManager::class)->setArgument(3, new Reference(OrmMetadataCache::class));

        (new OrmMetadataCachePass())->process($container);

        // The pass should not re-register OrmMetadataCache or change arg 3
        $arg3 = $container->getDefinition(EntityManager::class)->getArgument(3);
        $this->assertInstanceOf(Reference::class, $arg3);
        $this->assertSame(OrmMetadataCache::class, (string) $arg3);
    }
}
