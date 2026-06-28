<?php

declare(strict_types=1);

namespace Vortos\Foundation\DependencyInjection\Compiler;

use ReflectionClass;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Foundation\DependencyInjection\Attribute\OverrideImpl;

/**
 * Processes #[OverrideImpl] attributes and unconditionally replaces interface aliases.
 *
 * Unlike DefaultImplCompilerPass, this pass does not skip interfaces that already
 * have an alias or definition — it always wins. All errors are collected and thrown
 * as a single exception so the developer sees every misconfiguration at once.
 *
 * Compile-time errors (collected, not thrown immediately):
 *   - #[OverrideImpl] with no argument on a class that implements 0 or 2+ app interfaces.
 *   - #[OverrideImpl(X::class)] where X is not implemented by the class.
 *   - Two classes both override the same interface.
 *
 * Runs at TYPE_BEFORE_OPTIMIZATION priority 0 — after DefaultImplCompilerPass (priority 5).
 */
final class OverrideImplCompilerPass implements CompilerPassInterface
{
    use ResolveInterfaceTrait;

    public function process(ContainerBuilder $container): void
    {
        $projectDir = $container->hasParameter('kernel.project_dir')
            ? (string) $container->getParameter('kernel.project_dir')
            : null;

        $appNamespaces = $this->resolveAppNamespaces($projectDir);

        $errors   = [];
        $bindings = [];
        $seen     = [];

        foreach ($container->findTaggedServiceIds('vortos.override_impl') as $serviceId => $_) {
            $definition = $container->getDefinition($serviceId);
            $className  = $definition->getClass() ?? $serviceId;

            if (!class_exists($className)) {
                continue;
            }

            $reflClass = new ReflectionClass($className);
            $attrs     = $reflClass->getAttributes(OverrideImpl::class);

            if (empty($attrs)) {
                continue;
            }

            /** @var OverrideImpl $attribute */
            $attribute = $attrs[0]->newInstance();

            try {
                $interface = $this->resolveInterface($attribute->interface, $reflClass, $appNamespaces);
            } catch (\LogicException $e) {
                $errors[] = $this->formatError($reflClass, $e->getMessage());
                continue;
            }

            if (isset($seen[$interface])) {
                $errors[] = $this->formatError(
                    $reflClass,
                    sprintf(
                        '#[OverrideImpl] conflict: both "%s" and "%s" override "%s". Only one class may override an interface.',
                        $seen[$interface],
                        $className,
                        $interface,
                    ),
                );
                continue;
            }

            $seen[$interface] = $className;

            $container->setAlias($interface, $serviceId)->setPublic(true);

            $bindings[$interface] = [
                'class' => $className,
                'file'  => $reflClass->getFileName() ?: '',
            ];
        }

        if ($errors !== []) {
            throw new \LogicException($this->formatCombinedError($errors));
        }

        $container->setParameter('vortos.override_impl.bindings', $bindings);
    }

    private function formatError(ReflectionClass $reflClass, string $reason): string
    {
        return sprintf(
            '#[OverrideImpl] on "%s" (%s:%d)' . "\n" . '    → %s',
            $reflClass->getName(),
            $reflClass->getFileName() ?: 'unknown',
            $reflClass->getStartLine(),
            $reason,
        );
    }

    private function formatCombinedError(array $errors): string
    {
        $count = count($errors);
        $lines = sprintf('%d container configuration error(s) found:', $count) . "\n\n";

        foreach ($errors as $i => $error) {
            $lines .= sprintf('  [%d] ', $i + 1) . str_replace("\n", "\n  ", $error) . "\n\n";
        }

        return rtrim($lines);
    }
}
