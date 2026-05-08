<?php
declare(strict_types=1);

namespace Vortos\Authorization\Ownership\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Authorization\Ownership\Attribute\RequiresOwnership;
use Vortos\Authorization\Ownership\Attribute\RequiresOwnershipOrPermission;
use Vortos\Authorization\Ownership\Contract\OwnershipPolicyInterface;
use Vortos\Authorization\Ownership\Middleware\OwnershipMiddleware;

final class OwnershipCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(OwnershipMiddleware::class)) return;

        $routeMap = [];
        $policyServiceIds = [];

        foreach ($container->getDefinitions() as $serviceId => $definition) {
            $class = $definition->getClass();
            if (!$class || !class_exists($class)) continue;
            if (is_a($class, OwnershipPolicyInterface::class, true)) {
                $policyServiceIds[$class] = $serviceId;
            }
        }

        foreach ($container->getDefinitions() as $serviceId => $definition) {
            $class = $definition->getClass();
            if (!$class || !class_exists($class)) continue;
            if (!$definition->hasTag('vortos.api.controller') &&
                !$definition->hasTag('controller.service_arguments')) continue;

            $reflection = new \ReflectionClass($class);

            // Class-level attributes apply to the whole controller
            $ownershipAttrs = $reflection->getAttributes(RequiresOwnership::class);
            if (!empty($ownershipAttrs)) {
                $instance = $ownershipAttrs[0]->newInstance();
                $routeMap[$class] = ['type' => 'ownership', 'policy' => $instance->policy, 'override' => null];
            }

            $overrideAttrs = $reflection->getAttributes(RequiresOwnershipOrPermission::class);
            if (!empty($overrideAttrs)) {
                $instance = $overrideAttrs[0]->newInstance();
                $routeMap[$class] = ['type' => 'ownership_or_permission', 'policy' => $instance->policy, 'override' => $instance->override];
            }

            // Method-level attributes keyed as ControllerClass::methodName
            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() !== $class) {
                    continue;
                }

                $key = $class . '::' . $method->getName();

                $methodOwnershipAttrs = $method->getAttributes(RequiresOwnership::class);
                if (!empty($methodOwnershipAttrs)) {
                    $instance = $methodOwnershipAttrs[0]->newInstance();
                    $routeMap[$key] = ['type' => 'ownership', 'policy' => $instance->policy, 'override' => null];
                }

                $methodOverrideAttrs = $method->getAttributes(RequiresOwnershipOrPermission::class);
                if (!empty($methodOverrideAttrs)) {
                    $instance = $methodOverrideAttrs[0]->newInstance();
                    $routeMap[$key] = ['type' => 'ownership_or_permission', 'policy' => $instance->policy, 'override' => $instance->override];
                }
            }
        }

        $policyRefs = array_map(fn($id) => new Reference($id), $policyServiceIds);

        $container->getDefinition(OwnershipMiddleware::class)
            ->setArgument('$routeMap', $routeMap)
            ->setArgument('$policies', $policyRefs);
    }
}
