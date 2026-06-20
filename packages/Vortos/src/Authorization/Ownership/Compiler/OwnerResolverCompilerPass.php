<?php

declare(strict_types=1);

namespace Vortos\Authorization\Ownership\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Authorization\Ownership\Contract\OwnerResolverInterface;
use Vortos\Authorization\Ownership\OwnerResolverRegistry;

/**
 * Collects all OwnerResolverInterface services and injects them into the
 * OwnerResolverRegistry. Resolvers are matched by instanceof at runtime, so order
 * is not significant.
 */
final class OwnerResolverCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(OwnerResolverRegistry::class)) {
            return;
        }

        $references = [];

        foreach ($container->findTaggedServiceIds('vortos.owner_resolver') as $serviceId => $_tags) {
            $references[] = new Reference($serviceId);
        }

        // Also pick up resolvers that implement the interface but were registered
        // without autoconfiguration (e.g. manually defined services).
        foreach ($container->getDefinitions() as $serviceId => $definition) {
            $class = $definition->getClass();

            if ($class === null || !class_exists($class)) {
                continue;
            }

            if (is_a($class, OwnerResolverInterface::class, true)
                && !$definition->hasTag('vortos.owner_resolver')) {
                $references[] = new Reference($serviceId);
            }
        }

        $container->getDefinition(OwnerResolverRegistry::class)
            ->setArgument('$resolvers', $references);
    }
}
