<?php

declare(strict_types=1);

namespace Vortos\Metrics\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Metrics\Definition\MetricDefinitionProviderInterface;
use Vortos\Metrics\Definition\MetricDefinitionRegistry;

/**
 * Collects every service tagged {@see MetricDefinitionProviderInterface::TAG} and merges
 * its declarations into the {@see MetricDefinitionRegistry} factory argument at compile time.
 *
 * Tagged providers must have a no-argument constructor — their definitions are resolved
 * statically during container compilation, not at runtime.
 */
final class MetricDefinitionsCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(MetricDefinitionRegistry::class)) {
            return;
        }

        $registryDef = $container->getDefinition(MetricDefinitionRegistry::class);
        $existing    = $registryDef->getArgument('$definitions');

        $additional = [];
        foreach ($container->findTaggedServiceIds(MetricDefinitionProviderInterface::TAG) as $id => $_) {
            $class = $container->getDefinition($id)->getClass() ?? $id;
            if (!class_exists($class)) {
                continue;
            }
            /** @var MetricDefinitionProviderInterface $provider */
            $provider = new $class();
            foreach ($provider->definitions() as $definition) {
                $additional[] = $definition->toArray();
            }
        }

        if ($additional !== []) {
            $registryDef->setArgument('$definitions', array_merge($existing, $additional));
        }
    }
}
