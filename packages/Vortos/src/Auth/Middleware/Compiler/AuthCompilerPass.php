<?php

declare(strict_types=1);

namespace Vortos\Auth\Middleware\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Auth\Attribute\RequiresAuth;
use Vortos\Auth\Middleware\AuthMiddleware;

/**
 * Scans all controllers for #[RequiresAuth] at compile time.
 * Builds the protected controller list — zero reflection at runtime.
 */
final class AuthCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(AuthMiddleware::class)) {
            return;
        }

        $protectedControllers = [];

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
            if (!empty($reflection->getAttributes(RequiresAuth::class))) {
                $protectedControllers[] = $class;
            }
        }

        $container->getDefinition(AuthMiddleware::class)
            ->setArgument('$protectedControllers', $protectedControllers);
    }
}
