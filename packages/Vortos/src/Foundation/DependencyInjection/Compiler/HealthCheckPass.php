<?php

declare(strict_types=1);

namespace Vortos\Foundation\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Foundation\Health\Attribute\AsHealthCheck;
use Vortos\Foundation\Health\HealthRegistry;

final class HealthCheckPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(HealthRegistry::class)) {
            return;
        }

        $this->autoRegister($container);

        $checks = [];

        foreach ($container->getDefinitions() as $id => $definition) {
            $class = $definition->getClass();

            if (!$class || !class_exists($class)) {
                continue;
            }

            $ref = new \ReflectionClass($class);

            if ($ref->getAttributes(AsHealthCheck::class) !== []) {
                $checks[] = new Reference($id);
            }
        }

        $container->findDefinition(HealthRegistry::class)
            ->setArgument('$checks', $checks);
    }

    private function autoRegister(ContainerBuilder $container): void
    {
        $srcDir = $container->getParameter('kernel.project_dir') . '/src';

        if (!is_dir($srcDir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($srcDir));

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $class = $this->extractFqcn($file->getPathname());

            if ($class === null || !class_exists($class)) {
                continue;
            }

            $ref = new \ReflectionClass($class);

            if ($ref->getAttributes(AsHealthCheck::class) === []) {
                continue;
            }

            if ($container->has($class)) {
                continue;
            }

            $container->register($class, $class)
                ->setAutowired(true)
                ->setPublic(false);
        }
    }

    private function extractFqcn(string $file): ?string
    {
        $contents = file_get_contents($file);

        if (!preg_match('/^namespace\s+([^;]+);/m', $contents, $ns)) {
            return null;
        }

        if (!preg_match('/^(?:(?:final|abstract|readonly)\s+)*class\s+(\w+)/m', $contents, $cl)) {
            return null;
        }

        return trim($ns[1]) . '\\' . trim($cl[1]);
    }
}
