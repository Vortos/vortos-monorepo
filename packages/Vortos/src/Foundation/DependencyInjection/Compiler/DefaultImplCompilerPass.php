<?php

declare(strict_types=1);

namespace Vortos\Foundation\DependencyInjection\Compiler;

use ReflectionClass;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Foundation\DependencyInjection\Attribute\DefaultImpl;

/**
 * Processes #[DefaultImpl] attributes and creates interface → class aliases.
 *
 * For each service tagged 'vortos.default_impl':
 *   1. Read the #[DefaultImpl] attribute from the class.
 *   2. Resolve the target interface (explicit arg or single-interface inference).
 *   3. Register an alias if none already exists (explicit services.php wins).
 *
 * Compile-time errors:
 *   - #[DefaultImpl] with no argument on a class that implements 0 or 2+ app interfaces.
 *   - #[DefaultImpl(SomeInterface::class)] where SomeInterface is not implemented by the class.
 *
 * Runs at TYPE_BEFORE_OPTIMIZATION priority 5 — after all extensions are merged
 * but before service optimization, so aliases are visible to later passes.
 */
final class DefaultImplCompilerPass implements CompilerPassInterface
{
    use ResolveInterfaceTrait;

    public function process(ContainerBuilder $container): void
    {
        $projectDir  = $container->hasParameter('kernel.project_dir')
            ? (string) $container->getParameter('kernel.project_dir')
            : null;

        $appNamespaces = $this->resolveAppNamespaces($projectDir);

        $bindings = [];

        foreach ($container->findTaggedServiceIds('vortos.default_impl') as $serviceId => $_) {
            $definition = $container->getDefinition($serviceId);
            $className  = $definition->getClass() ?? $serviceId;

            if (!class_exists($className)) {
                continue;
            }

            $reflClass = new ReflectionClass($className);
            $attrs     = $reflClass->getAttributes(DefaultImpl::class);

            if (empty($attrs)) {
                continue;
            }

            /** @var DefaultImpl $attribute */
            $attribute = $attrs[0]->newInstance();

            $interface = $this->resolveInterface($attribute->interface, $reflClass, $appNamespaces);

            if ($container->hasAlias($interface) || $container->hasDefinition($interface)) {
                // Explicit registration wins — do not override.
                continue;
            }

            $container->setAlias($interface, $serviceId)->setPublic(true);

            $bindings[$interface] = [
                'class' => $className,
                'file'  => $reflClass->getFileName() ?: '',
            ];
        }

        $container->setParameter('vortos.default_impl.bindings', $bindings);
    }
}
