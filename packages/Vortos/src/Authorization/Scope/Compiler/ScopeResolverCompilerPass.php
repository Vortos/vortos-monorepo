<?php
declare(strict_types=1);

namespace Vortos\Authorization\Scope\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Authorization\Scope\Contract\ScopeResolverInterface;
use Vortos\Authorization\Scope\ScopeResolverRegistry;

/**
 * Discovers all ScopeResolverInterface implementations and registers them
 * in the ScopeResolverRegistry keyed by scope name.
 */
final class ScopeResolverCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(ScopeResolverRegistry::class)) return;

        $resolvers = [];

        foreach ($container->getDefinitions() as $serviceId => $definition) {
            $class = $definition->getClass();
            if (!$class || !class_exists($class)) continue;
            if (!is_a($class, ScopeResolverInterface::class, true)) continue;

            // Instantiate temporarily to get scope name
            try {
                $instance = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
                $scopeName = $instance->getScopeName();
                $resolvers[$scopeName] = new Reference($serviceId);
            } catch (\Throwable) {
                // Skip if can't determine scope name
            }
        }

        $container->getDefinition(ScopeResolverRegistry::class)
            ->setArgument('$resolvers', $resolvers);
    }
}
