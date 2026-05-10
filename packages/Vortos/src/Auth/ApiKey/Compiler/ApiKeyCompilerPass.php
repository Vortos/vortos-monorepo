<?php

declare(strict_types=1);

namespace Vortos\Auth\ApiKey\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Auth\ApiKey\Attribute\RequiresApiKey;
use Vortos\Auth\ApiKey\Middleware\ApiKeyAuthMiddleware;

/**
 * Scans controllers for #[RequiresApiKey] at compile time.
 * Builds routeMap injected into ApiKeyAuthMiddleware. Zero reflection at runtime.
 */
final class ApiKeyCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(ApiKeyAuthMiddleware::class)) {
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
                foreach ($method->getAttributes(RequiresApiKey::class) as $attr) {
                    $instance = $attr->newInstance();
                    $routeMap[$class . '::' . $method->getName()] = [
                        'scopes' => $instance->scopes,
                    ];
                }
            }

            foreach ($reflection->getAttributes(RequiresApiKey::class) as $attr) {
                $instance = $attr->newInstance();
                $routeMap[$class] = [
                    'scopes' => $instance->scopes,
                ];
            }
        }

        $container->getDefinition(ApiKeyAuthMiddleware::class)
            ->setArgument('$routeMap', $routeMap);
    }
}
