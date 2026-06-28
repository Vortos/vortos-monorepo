<?php

declare(strict_types=1);

namespace Vortos\Foundation\DependencyInjection\Compiler;

use ReflectionClass;

trait ResolveInterfaceTrait
{
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
    private function resolveInterface(
        ?string $explicitInterface,
        ReflectionClass $reflClass,
        array $appNamespaces,
    ): string {
        if ($explicitInterface !== null) {
            if (!$reflClass->implementsInterface($explicitInterface)) {
                throw new \LogicException(sprintf(
                    'Class does not implement "%s". Add "implements %s" or fix the attribute argument.',
                    $explicitInterface,
                    $explicitInterface,
                ));
            }

            return $explicitInterface;
        }

        $appInterfaces = array_values(array_filter(
            $reflClass->getInterfaceNames(),
            fn(string $iface) => $this->isAppInterface($iface, $appNamespaces),
        ));

        if (count($appInterfaces) === 1) {
            return $appInterfaces[0];
        }

        if (count($appInterfaces) === 0) {
            throw new \LogicException(
                'Class implements no application interfaces. '
                . 'Either add an interface or specify it explicitly via the attribute argument.',
            );
        }

        throw new \LogicException(sprintf(
            'Ambiguous — the class implements multiple application interfaces: %s. '
            . 'Specify which one to alias via the attribute argument.',
            implode(', ', $appInterfaces),
        ));
    }

    private function isAppInterface(string $interface, array $appNamespaces): bool
    {
        if (empty($appNamespaces)) {
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
