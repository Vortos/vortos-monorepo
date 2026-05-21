<?php

declare(strict_types=1);

namespace Vortos\Cqrs\Http\Middleware\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Cqrs\Http\Attribute\RequiresIdempotencyKey;
use Vortos\Cqrs\Http\Middleware\IdempotencyKeyMiddleware;

/**
 * Scans all controllers for #[RequiresIdempotencyKey] at compile time.
 * Builds the enforced controller list — zero reflection at runtime.
 */
final class IdempotencyKeyMiddlewarePass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(IdempotencyKeyMiddleware::class)) {
            return;
        }

        $enforcedControllers = [];

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
            if (!empty($reflection->getAttributes(RequiresIdempotencyKey::class))) {
                $enforcedControllers[] = $class;
            }
        }

        $container->getDefinition(IdempotencyKeyMiddleware::class)
            ->setArgument('$enforcedControllers', $enforcedControllers);
    }
}
