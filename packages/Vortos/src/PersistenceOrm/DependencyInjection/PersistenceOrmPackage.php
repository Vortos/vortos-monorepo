<?php

declare(strict_types=1);

namespace Vortos\PersistenceOrm\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Vortos\Foundation\Contract\PackageInterface;
use Vortos\PersistenceDbal\N1Detection\N1DetectionCompilerPass;
use Vortos\PersistenceOrm\DependencyInjection\Compiler\OrmMetadataCachePass;

final class PersistenceOrmPackage implements PackageInterface
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new PersistenceOrmExtension();
    }

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new N1DetectionCompilerPass());
        // TYPE_BEFORE_OPTIMIZATION runs after all extensions have been merged —
        // OrmMetadataCachePass can safely check for TaggedCacheInterface here.
        $container->addCompilerPass(new OrmMetadataCachePass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 10);
    }
}
