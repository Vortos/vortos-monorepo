<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\FeatureFlags\Attribute\RequiresFlag;
use Vortos\FeatureFlags\Http\FeatureFlagMiddleware;

/**
 * Resolves #[RequiresFlag] attributes on controllers at compile time.
 *
 * Builds a map of 'ClassName::methodName' → 'flag-name' by reading
 * controller classes tagged 'vortos.api.controller'. The compiled map is
 * injected into FeatureFlagMiddleware as a constructor argument, eliminating
 * all runtime reflection from the request path.
 *
 * Runs at priority 70 — after RouteCompilerPass (80) has tagged controllers,
 * before ResolveNamedArgumentsPass converts named args to positional.
 */
final class FeatureFlagsCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(FeatureFlagMiddleware::class)) {
            return;
        }

        $flagMap = [];

        foreach ($container->findTaggedServiceIds('vortos.api.controller') as $id => $tags) {
            $class = $container->getDefinition($id)->getClass();

            if ($class === null || !class_exists($class)) {
                continue;
            }

            $rc = new \ReflectionClass($class);

            $classAttrs = $rc->getAttributes(RequiresFlag::class);
            if ($classAttrs !== []) {
                $flagMap[$class . '::__invoke'] = $classAttrs[0]->newInstance()->flag;
            }

            foreach ($rc->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() !== $class) {
                    continue;
                }

                $methodAttrs = $method->getAttributes(RequiresFlag::class);
                if ($methodAttrs !== []) {
                    $flagMap[$class . '::' . $method->getName()] = $methodAttrs[0]->newInstance()->flag;
                }
            }
        }

        $container->findDefinition(FeatureFlagMiddleware::class)
            ->setArgument('$flagMap', $flagMap);
    }
}
