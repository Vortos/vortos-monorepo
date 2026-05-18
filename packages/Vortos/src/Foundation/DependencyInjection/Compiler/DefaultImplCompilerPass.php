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
    public function process(ContainerBuilder $container): void
    {
        $projectDir  = $container->hasParameter('kernel.project_dir')
            ? (string) $container->getParameter('kernel.project_dir')
            : null;

        $appNamespaces = $this->resolveAppNamespaces($projectDir);

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

            $interface = $this->resolveInterface($attribute, $reflClass, $appNamespaces);

            if ($container->hasAlias($interface) || $container->hasDefinition($interface)) {
                // Explicit registration wins — do not override.
                continue;
            }

            $container->setAlias($interface, $serviceId)->setPublic(true);
        }
    }

    /** @return string[] App namespace prefixes (e.g. ['App\\', 'Vortos\\']) */
    private function resolveAppNamespaces(?string $projectDir): array
    {
        if ($projectDir === null) {
            return [];
        }

        $composerJson = $projectDir . '/composer.json';

        if (!file_exists($composerJson)) {
            return [];
        }

        $decoded = json_decode(file_get_contents($composerJson), true);

        $prefixes = [];

        foreach ($decoded['autoload']['psr-4'] ?? [] as $ns => $_) {
            $prefixes[] = rtrim($ns, '\\') . '\\';
        }

        foreach ($decoded['autoload-dev']['psr-4'] ?? [] as $ns => $_) {
            $prefixes[] = rtrim($ns, '\\') . '\\';
        }

        return array_unique($prefixes);
    }

    /**
     * @return class-string
     * @throws \LogicException on ambiguous or invalid configuration
     */
    private function resolveInterface(DefaultImpl $attribute, ReflectionClass $reflClass, array $appNamespaces): string
    {
        if ($attribute->interface !== null) {
            if (!$reflClass->implementsInterface($attribute->interface)) {
                throw new \LogicException(sprintf(
                    '#[DefaultImpl(%s)] on class "%s" but the class does not implement that interface.',
                    $attribute->interface,
                    $reflClass->getName(),
                ));
            }

            return $attribute->interface;
        }

        // No explicit interface — infer from implemented interfaces.
        $appInterfaces = array_filter(
            $reflClass->getInterfaceNames(),
            fn(string $iface) => $this->isAppInterface($iface, $appNamespaces),
        );

        $appInterfaces = array_values($appInterfaces);

        if (count($appInterfaces) === 1) {
            return $appInterfaces[0];
        }

        if (count($appInterfaces) === 0) {
            throw new \LogicException(sprintf(
                '#[DefaultImpl] on "%s" but the class implements no application interfaces. '
                . 'Either add an interface or specify it explicitly: #[DefaultImpl(MyInterface::class)].',
                $reflClass->getName(),
            ));
        }

        throw new \LogicException(sprintf(
            '#[DefaultImpl] on "%s" is ambiguous — the class implements multiple application interfaces: %s. '
            . 'Specify which one to alias: #[DefaultImpl(MyInterface::class)].',
            $reflClass->getName(),
            implode(', ', $appInterfaces),
        ));
    }

    private function isAppInterface(string $interface, array $appNamespaces): bool
    {
        if (empty($appNamespaces)) {
            // No composer.json found — treat every non-PHP-stdlib interface as an app interface.
            return !str_starts_with($interface, 'Traversable')
                && !in_array($interface, ['Iterator', 'Countable', 'Stringable', 'Serializable', 'ArrayAccess'], true);
        }

        foreach ($appNamespaces as $prefix) {
            if (str_starts_with($interface, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
