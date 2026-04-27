<?php
declare(strict_types=1);

namespace Vortos\Auth\Quota\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Auth\Quota\Attribute\RequiresQuota;
use Vortos\Auth\Quota\Contract\QuotaPolicyInterface;
use Vortos\Auth\Quota\Middleware\QuotaMiddleware;

final class QuotaCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(QuotaMiddleware::class)) return;

        $routeMap = [];
        $policyServiceIds = [];

        foreach ($container->getDefinitions() as $serviceId => $definition) {
            $class = $definition->getClass();
            if (!$class || !class_exists($class)) continue;
            if (is_a($class, QuotaPolicyInterface::class, true)) {
                $policyServiceIds[$class] = $serviceId;
            }
        }

        foreach ($container->getDefinitions() as $serviceId => $definition) {
            $class = $definition->getClass();
            if (!$class || !class_exists($class)) continue;
            if (!$definition->hasTag('vortos.api.controller') &&
                !$definition->hasTag('controller.service_arguments')) continue;

            $reflection = new \ReflectionClass($class);
            foreach ($reflection->getAttributes(RequiresQuota::class) as $attr) {
                $instance = $attr->newInstance();
                $routeMap[$class][] = ['quota' => $instance->quota, 'cost' => $instance->cost];
            }
        }

        $policyRefs = array_map(fn($id) => new Reference($id), $policyServiceIds);

        $container->getDefinition(QuotaMiddleware::class)
            ->setArgument('$routeMap', $routeMap)
            ->setArgument('$policies', $policyRefs);
    }
}
