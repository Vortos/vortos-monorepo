<?php

declare(strict_types=1);

namespace Vortos\Http\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Http\Kernel;

final class RegisterTerminablePass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(Kernel::class)) {
            return;
        }

        $terminable = [];

        foreach ($container->getDefinitions() as $id => $definition) {
            if ($definition->isAbstract() || $definition->isSynthetic()) {
                continue;
            }
            $class = $definition->getClass();
            if (!$class || !class_exists($class)) {
                continue;
            }
            if (is_a($class, \Vortos\Http\Contract\TerminableMiddlewareInterface::class, true)) {
                $terminable[] = $id;
            }
        }

        $references = array_map(
            static fn(string $id): Reference => new Reference($id),
            $terminable,
        );

        $container->getDefinition(Kernel::class)
            ->setArgument('$terminableMiddleware', $references);
    }
}
