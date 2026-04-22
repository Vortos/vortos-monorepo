<?php

declare(strict_types=1);

namespace Vortos\Messaging\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Rebuilds vortos.handler_locator from the final vortos.handlers parameter.
 *
 * Runs after HandlerDiscoveryCompilerPass (90) and ProjectionDiscoveryCompilerPass (85).
 * Both passes write serviceIds into vortos.handlers. This pass reads all of them
 * and populates the locator in one shot — no merging, no ordering dependency.
 *
 * Priority 80 — after all discovery passes have finished.
 */
final class HandlerLocatorPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('vortos.handlers')) {
            return;
        }

        if (!$container->hasDefinition('vortos.handler_locator')) {
            return;
        }

        $handlers = $container->getParameter('vortos.handlers');

        $serviceIds = [];

        foreach ($handlers as $consumer => $eventHandlers) {
            foreach ($eventHandlers as $eventClass => $descriptors) {
                foreach ($descriptors as $descriptor) {
                    $serviceId = $descriptor['serviceId'];
                    $serviceIds[$serviceId] = new Reference($serviceId);
                }
            }
        }

        $container->getDefinition('vortos.handler_locator')
            ->setArguments([$serviceIds]);
    }
}
