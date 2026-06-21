<?php

declare(strict_types=1);

namespace Vortos\PersistenceOrm\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Vortos\Foundation\Contract\PackageInterface;
use Vortos\PersistenceDbal\N1Detection\N1DetectionCompilerPass;
use Vortos\PersistenceOrm\DependencyInjection\Compiler\OrmMetadataCachePass;
use Vortos\PersistenceOrm\DependencyInjection\Compiler\OrmRepositoryCompilerPass;
use Vortos\PersistenceOrm\DependencyInjection\Compiler\TenantOrmWiringPass;
use Vortos\PersistenceOrm\DependencyInjection\Compiler\TenantScopedEntitiesPass;

final class PersistenceOrmPackage implements PackageInterface
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new PersistenceOrmExtension();
    }

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(
            new OrmRepositoryCompilerPass(),
            PassConfig::TYPE_BEFORE_OPTIMIZATION,
            8,
        );
        // Runs after OrmRepositoryCompilerPass (priority 8) so all repository
        // definitions — and thus their entity classes — are available to scan.
        $container->addCompilerPass(
            new TenantScopedEntitiesPass(),
            PassConfig::TYPE_BEFORE_OPTIMIZATION,
            6,
        );
        $container->addCompilerPass(new N1DetectionCompilerPass());
        // TYPE_BEFORE_OPTIMIZATION runs after all extensions have been merged —
        // OrmMetadataCachePass can safely check for TaggedCacheInterface here.
        $container->addCompilerPass(new OrmMetadataCachePass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 10);
        // Patches tenant wiring onto the EntityManager/OrmUnitOfWork when the
        // tenant package is present. Priority 5 keeps it after TenantScopedEntitiesPass.
        $container->addCompilerPass(new TenantOrmWiringPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 5);
    }
}
