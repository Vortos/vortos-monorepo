<?php
declare(strict_types=1);

namespace Vortos\Foundation\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Contracts\Service\ResetInterface;
use Vortos\Foundation\Reset\ServicesResetter;

final class ResettableServicesPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(ServicesResetter::class)) {
            return;
        }

        $services = [];
        $serviceIds = [];

        foreach ($container->getDefinitions() as $serviceId => $definition) {
            $class = $definition->getClass();

            if ($class === null || !class_exists($class)) {
                continue;
            }

            if (!is_a($class, ResetInterface::class, true)) {
                continue;
            }

            $services[$serviceId] = new Reference($serviceId);
            $serviceIds[] = $serviceId;
        }

        $locator = (new Definition(ServiceLocator::class, [$services]))
            ->addTag('container.service_locator');

        $container->getDefinition(ServicesResetter::class)
            ->setArgument('$services', $locator)
            ->setArgument('$serviceIds', $serviceIds);
    }
}
