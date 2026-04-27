<?php
declare(strict_types=1);

namespace Vortos\Auth\RateLimit\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Auth\RateLimit\Attribute\RateLimit;
use Vortos\Auth\RateLimit\Contract\RateLimitPolicyInterface;
use Vortos\Auth\RateLimit\Middleware\RateLimitMiddleware;

/**
 * Scans all controllers for #[RateLimit] at compile time.
 * Builds route map and policy map — zero reflection at runtime.
 *
 * Also discovers all RateLimitPolicyInterface implementations
 * and registers them in the policy map.
 */
final class RateLimitCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(RateLimitMiddleware::class)) return;

        $routeMap = [];
        $policyServiceIds = [];

        // Discover all RateLimitPolicyInterface implementations
        foreach ($container->getDefinitions() as $serviceId => $definition) {
            $class = $definition->getClass();
            if (!$class || !class_exists($class)) continue;
            if (is_a($class, RateLimitPolicyInterface::class, true)) {
                $policyServiceIds[$class] = $serviceId;
            }
        }

        // Scan all controllers for #[RateLimit]
        foreach ($container->getDefinitions() as $serviceId => $definition) {
            $class = $definition->getClass();
            if (!$class || !class_exists($class)) continue;
            if (!$definition->hasTag('vortos.api.controller') &&
                !$definition->hasTag('controller.service_arguments')) continue;

            $reflection = new \ReflectionClass($class);
            $attrs = $reflection->getAttributes(RateLimit::class);

            if (empty($attrs)) {
                // Check methods too
                foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                    $methodAttrs = $method->getAttributes(RateLimit::class);
                    if (!empty($methodAttrs)) {
                        foreach ($methodAttrs as $attr) {
                            $instance = $attr->newInstance();
                            $routeMap[$class][] = [
                                'policy' => $instance->policy,
                                'per'    => $instance->per,
                            ];
                        }
                    }
                }
                continue;
            }

            foreach ($attrs as $attr) {
                $instance = $attr->newInstance();
                $routeMap[$class][] = [
                    'policy' => $instance->policy,
                    'per'    => $instance->per,
                ];
            }
        }

        // Build policy references
        $policyRefs = [];
        foreach ($policyServiceIds as $class => $serviceId) {
            $policyRefs[$class] = new Reference($serviceId);
        }

        $container->getDefinition(RateLimitMiddleware::class)
            ->setArgument('$routeMap', $routeMap)
            ->setArgument('$policies', $policyRefs);
    }
}
