<?php

declare(strict_types=1);

namespace Vortos\Deploy\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Deploy\Strategy\DeployStrategyRegistry;

final class CollectDeployStrategiesPass implements CompilerPassInterface
{
    public const TAG = 'vortos.deploy.strategy';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(DeployStrategyRegistry::class)) {
            return;
        }

        $registryDef = $container->getDefinition(DeployStrategyRegistry::class);

        foreach ($container->findTaggedServiceIds(self::TAG) as $serviceId => $tags) {
            $registryDef->addMethodCall('register', [new Reference($serviceId)]);
        }
    }
}
