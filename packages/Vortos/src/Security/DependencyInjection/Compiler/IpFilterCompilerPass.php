<?php

declare(strict_types=1);

namespace Vortos\Security\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Security\IpFilter\Attribute\AllowIp;
use Vortos\Security\IpFilter\Attribute\DenyIp;
use Vortos\Security\IpFilter\Middleware\IpFilterMiddleware;

/**
 * Scans controllers for #[AllowIp] and #[DenyIp] at compile time.
 * Builds a per-controller override map injected into IpFilterMiddleware.
 * Zero reflection at runtime.
 */
final class IpFilterCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(IpFilterMiddleware::class)) {
            return;
        }

        $routeMap = [];

        foreach ($container->getDefinitions() as $definition) {
            $class = $definition->getClass();
            if (!$class || !class_exists($class)) {
                continue;
            }
            if (!$definition->hasTag('vortos.api.controller') &&
                !$definition->hasTag('controller.service_arguments')) {
                continue;
            }

            $reflection = new \ReflectionClass($class);

            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                $allow = array_map(
                    fn(\ReflectionAttribute $a) => $a->newInstance()->cidrs,
                    $method->getAttributes(AllowIp::class)
                );
                $deny = array_map(
                    fn(\ReflectionAttribute $a) => $a->newInstance()->cidrs,
                    $method->getAttributes(DenyIp::class)
                );

                if ($allow || $deny) {
                    $routeMap[$class . '::' . $method->getName()] = [
                        'allow' => array_merge([], ...$allow),
                        'deny'  => array_merge([], ...$deny),
                    ];
                }
            }

            // Class-level attributes apply to all methods
            $classAllow = array_map(
                fn(\ReflectionAttribute $a) => $a->newInstance()->cidrs,
                $reflection->getAttributes(AllowIp::class)
            );
            $classDeny = array_map(
                fn(\ReflectionAttribute $a) => $a->newInstance()->cidrs,
                $reflection->getAttributes(DenyIp::class)
            );

            if ($classAllow || $classDeny) {
                $routeMap[$class] = [
                    'allow' => array_merge([], ...$classAllow),
                    'deny'  => array_merge([], ...$classDeny),
                ];
            }
        }

        $container->getDefinition(IpFilterMiddleware::class)
            ->setArgument('$routeMap', $routeMap);
    }
}
