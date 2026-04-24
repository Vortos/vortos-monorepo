<?php

declare(strict_types=1);

namespace Vortos\Authorization\DependencyInjection\Compiler;

use ReflectionClass;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Authorization\Attribute\AsPolicy;

/**
 * Discovers all classes tagged 'vortos.policy' and registers them
 * in the PolicyRegistry ServiceLocator keyed by resource name.
 *
 * Discovery:
 *   1. Find all services tagged 'vortos.policy'
 *   2. Read resource name from #[AsPolicy] attribute OR from the tag itself
 *   3. Build resource → Reference map
 *   4. Inject into PolicyRegistry ServiceLocator
 *
 * Compile-time validation:
 *   - Throws if two policies registered for the same resource
 *   - Throws if a tagged service has no #[AsPolicy] attribute and no resource in tag
 */
final class PolicyRegistryPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $taggedPolicies = $container->findTaggedServiceIds('vortos.policy');

        $policyMap = [];

        foreach ($taggedPolicies as $serviceId => $tags) {
            foreach ($tags as $tag) {
                $resource = $tag['resource'] ?? null;

                if ($resource === null) {
                    $className = $container->getDefinition($serviceId)->getClass() ?? $serviceId;

                    if (class_exists($className)) {
                        $reflection = new ReflectionClass($className);
                        $attrs = $reflection->getAttributes(AsPolicy::class);

                        if (!empty($attrs)) {
                            $resource = $attrs[0]->newInstance()->resource;
                        }
                    }
                }

                if ($resource === null) {
                    throw new \LogicException(sprintf(
                        'Policy service "%s" is tagged "vortos.policy" but has no resource defined. '
                            . 'Add #[AsPolicy(resource: "your_resource")] to the class.',
                        $serviceId,
                    ));
                }

                if (isset($policyMap[$resource])) {
                    throw new \LogicException(sprintf(
                        'Two policies registered for resource "%s": "%s" and "%s". '
                            . 'Each resource must have exactly one policy.',
                        $resource,
                        (string) $policyMap[$resource],
                        $serviceId,
                    ));
                }

                $policyMap[$resource] = new Reference($serviceId);
            }
        }

        if (!$container->hasDefinition('vortos.authorization.policy_locator')) {
            return;
        }

        $container->getDefinition('vortos.authorization.policy_locator')
            ->setArguments([$policyMap]);
    }
}
