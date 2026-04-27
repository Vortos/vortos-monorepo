<?php
declare(strict_types=1);

namespace Vortos\Auth\FeatureAccess\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Auth\FeatureAccess\Attribute\RequiresFeatureAccess;
use Vortos\Auth\FeatureAccess\Contract\FeatureAccessPolicyInterface;
use Vortos\Auth\FeatureAccess\Middleware\FeatureAccessMiddleware;

final class FeatureAccessCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(FeatureAccessMiddleware::class)) return;

        $routeMap = [];
        $policyServiceIds = [];

        foreach ($container->getDefinitions() as $serviceId => $definition) {
            $class = $definition->getClass();
            if (!$class || !class_exists($class)) continue;
            if (is_a($class, FeatureAccessPolicyInterface::class, true)) {
                $policyServiceIds[$class] = $serviceId;
            }
        }

        foreach ($container->getDefinitions() as $serviceId => $definition) {
            $class = $definition->getClass();
            if (!$class || !class_exists($class)) continue;
            if (!$definition->hasTag('vortos.api.controller') &&
                !$definition->hasTag('controller.service_arguments')) continue;

            $reflection = new \ReflectionClass($class);
            $this->scanForAttribute($reflection, RequiresFeatureAccess::class, $routeMap, $class);
        }

        $policyRefs = array_map(fn($id) => new Reference($id), $policyServiceIds);

        $container->getDefinition(FeatureAccessMiddleware::class)
            ->setArgument('$routeMap', $routeMap)
            ->setArgument('$policies', $policyRefs);
    }

    private function scanForAttribute(\ReflectionClass $reflection, string $attrClass, array &$map, string $class): void
    {
        foreach ($reflection->getAttributes($attrClass) as $attr) {
            $instance = $attr->newInstance();
            $map[$class][] = ['feature' => $instance->feature, 'paymentRequired' => $instance->paymentRequired];
        }
    }
}
