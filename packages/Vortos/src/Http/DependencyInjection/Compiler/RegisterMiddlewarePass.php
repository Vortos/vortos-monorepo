<?php

declare(strict_types=1);

namespace Vortos\Http\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Http\Pipeline\Pipeline;

final class RegisterMiddlewarePass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(Pipeline::class)) {
            return;
        }

        $middleware = [];

        foreach ($container->getDefinitions() as $id => $definition) {
            if ($definition->isAbstract() || $definition->isSynthetic()) {
                continue;
            }
            $class = $definition->getClass();
            if (!$class || !class_exists($class)) {
                continue;
            }
            if (!is_a($class, \Vortos\Http\Contract\MiddlewareInterface::class, true)) {
                continue;
            }

            $priority = 0;
            $attrs = (new \ReflectionClass($class))->getAttributes(\Vortos\Http\Attribute\AsMiddleware::class);
            if ($attrs !== []) {
                $priority = $attrs[0]->newInstance()->order;
            }

            $middleware[$id] = $priority;
        }

        arsort($middleware); // highest order = outermost = first

        $references = array_map(
            static fn(string $id): Reference => new Reference($id),
            array_keys($middleware),
        );

        $container->getDefinition(Pipeline::class)
            ->setArgument(0, $references);
    }
}
