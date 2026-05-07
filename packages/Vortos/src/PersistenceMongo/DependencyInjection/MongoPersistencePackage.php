<?php

declare(strict_types=1);

namespace Vortos\PersistenceMongo\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Vortos\Foundation\Contract\PackageInterface;
use Vortos\PersistenceMongo\Tracing\MongoTracingCompilerPass;

/**
 * MongoDB persistence package.
 *
 * Registers MongoPersistenceExtension with the container.
 * Include this package in Container.php when using MongoDB for the read side.
 *
 * If you use a different read-side adapter (e.g. Elasticsearch, Redis),
 * omit this package and register your own Client equivalent instead.
 */
final class MongoPersistencePackage implements PackageInterface
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new MongoPersistenceExtension();
    }

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(
            new MongoTracingCompilerPass(),
            PassConfig::TYPE_BEFORE_OPTIMIZATION,
            0,
        );
    }
}
