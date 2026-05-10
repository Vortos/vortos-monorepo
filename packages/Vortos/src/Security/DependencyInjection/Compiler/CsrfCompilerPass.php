<?php

declare(strict_types=1);

namespace Vortos\Security\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Security\Csrf\Attribute\SkipCsrf;
use Vortos\Security\Csrf\Middleware\CsrfMiddleware;

/**
 * Scans controllers for #[SkipCsrf] at compile time.
 * Builds the skip-list injected into CsrfMiddleware. Zero reflection at runtime.
 */
final class CsrfCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(CsrfMiddleware::class)) {
            return;
        }

        $skipControllers = [];

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

            // Class-level skip applies to all actions in the controller
            if (!empty($reflection->getAttributes(SkipCsrf::class))) {
                $skipControllers[] = $class;
                continue;
            }

            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if (!empty($method->getAttributes(SkipCsrf::class))) {
                    $skipControllers[] = $class . '::' . $method->getName();
                }
            }
        }

        $container->getDefinition(CsrfMiddleware::class)
            ->setArgument('$skipControllers', $skipControllers);
    }
}
