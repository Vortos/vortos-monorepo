<?php

declare(strict_types=1);

namespace Vortos\Foundation\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Domain\Attribute\AsDomainService;
use Vortos\Foundation\DependencyInjection\Attribute\DefaultImpl;

/**
 * Registers explicitly attributed services from application Domain folders.
 *
 * The app service loader excludes Domain by default to keep aggregates, value
 * objects, events, and errors out of the container. This pass scans those
 * excluded folders at compile time and only registers classes that opt in with
 * #[AsDomainService].
 */
final class DomainServiceCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('kernel.project_dir')) {
            return;
        }

        $srcDir = (string) $container->getParameter('kernel.project_dir') . '/src';

        if (!is_dir($srcDir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS),
        );

        $classToServiceId = $this->buildClassToServiceIdMap($container);

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();

            if (!$this->isDomainPath($path)) {
                continue;
            }

            $class = $this->extractFqcn($path);

            if ($class === null || !class_exists($class)) {
                continue;
            }

            $reflection = new \ReflectionClass($class);

            if (!$reflection->isInstantiable()) {
                continue;
            }

            if ($reflection->getAttributes(AsDomainService::class) === []) {
                continue;
            }

            $existingServiceId = $container->hasDefinition($class)
                ? $class
                : ($classToServiceId[$class] ?? null);

            if ($existingServiceId !== null) {
                $definition = $container->getDefinition($existingServiceId);
            } elseif ($container->hasAlias($class)) {
                $definition = null;
            } else {
                $definition = $container->register($class, $class)
                    ->setAutowired(true)
                    ->setAutoconfigured(false)
                    ->setPublic(false);
            }

            if ($definition === null) {
                continue;
            }

            if (!$definition->hasTag('vortos.domain_service')) {
                $definition->addTag('vortos.domain_service');
            }

            if ($reflection->getAttributes(DefaultImpl::class) !== [] && !$definition->hasTag('vortos.default_impl')) {
                $definition->addTag('vortos.default_impl');
            }
        }
    }

    private function isDomainPath(string $path): bool
    {
        $normalized = str_replace('\\', '/', $path);

        return str_contains($normalized, '/src/')
            && preg_match('#/src/(?:Domain|[^/]+/Domain)(?:/|$)#', $normalized) === 1;
    }

    /** @return array<class-string, string> */
    private function buildClassToServiceIdMap(ContainerBuilder $container): array
    {
        $map = [];

        foreach ($container->getDefinitions() as $id => $definition) {
            $class = $definition->getClass();

            if ($class !== null && !isset($map[$class])) {
                $map[$class] = $id;
            }
        }

        return $map;
    }

    private function extractFqcn(string $file): ?string
    {
        $contents = (string) file_get_contents($file);

        if (!preg_match('/^namespace\s+([^;]+);/m', $contents, $namespace)) {
            return null;
        }

        if (!preg_match('/^\s*(?:(?:final|readonly|abstract)\s+)*class\s+(\w+)/m', $contents, $class)) {
            return null;
        }

        return trim($namespace[1]) . '\\' . trim($class[1]);
    }
}
