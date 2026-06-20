<?php

declare(strict_types=1);

namespace Vortos\Foundation\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Promotes classes tagged 'vortos.compiler_pass' into the container's pass pipeline.
 *
 * The tag is applied automatically by FoundationExtension when a class carries
 * #[AsCompilerPass]. This pass runs at TYPE_BEFORE_OPTIMIZATION priority 200 —
 * after AttributeAutoconfigurationPass (2048) has applied the tags, before any
 * lower-priority passes run.
 *
 * Security: each tagged class is validated against CompilerPassInterface before
 * instantiation, preventing a forged tag from causing arbitrary class execution.
 */
final class CompilerPassDiscoveryPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        foreach ($container->findTaggedServiceIds('vortos.compiler_pass') as $serviceId => $tags) {
            $definition = $container->getDefinition($serviceId);
            $className  = $definition->getClass() ?? $serviceId;

            if (!class_exists($className)) {
                throw new \LogicException(sprintf(
                    'Service "%s" tagged "vortos.compiler_pass" references class "%s" which does not exist.',
                    $serviceId,
                    $className,
                ));
            }

            if (!is_a($className, CompilerPassInterface::class, true)) {
                throw new \LogicException(sprintf(
                    'Class "%s" is tagged "vortos.compiler_pass" but does not implement %s.',
                    $className,
                    CompilerPassInterface::class,
                ));
            }

            $instance = new $className();

            foreach ($tags as $tag) {
                $type     = (string)  ($tag['type']     ?? PassConfig::TYPE_BEFORE_OPTIMIZATION);
                $priority = (int)     ($tag['priority'] ?? 0);

                $container->addCompilerPass($instance, $type, $priority);
            }

            // Compiler passes are not runtime services; remove the definition.
            $container->removeDefinition($serviceId);
        }
    }
}
