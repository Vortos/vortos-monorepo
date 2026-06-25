<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class CleanArchTest extends TestCase
{
    public function test_plan_does_not_depend_on_dependency_injection(): void
    {
        $this->assertDirectoryFreeOf(
            'Plan',
            ['Vortos\\Deploy\\DependencyInjection\\', 'Symfony\\Component\\DependencyInjection'],
            'Plan/ must not depend on DependencyInjection',
        );
    }

    public function test_strategy_does_not_depend_on_dependency_injection(): void
    {
        $this->assertDirectoryFreeOf(
            'Strategy',
            ['Vortos\\Deploy\\DependencyInjection\\', 'Symfony\\Component\\DependencyInjection'],
            'Strategy/ must not depend on DependencyInjection',
        );
    }

    public function test_plan_does_not_import_secret_value(): void
    {
        $this->assertDirectoryFreeOf(
            'Plan',
            ['Vortos\\Secrets\\Value\\SecretValue'],
            'Plan/ must never import SecretValue — only SecretReference',
        );
    }

    public function test_strategy_does_not_import_secret_value(): void
    {
        $this->assertDirectoryFreeOf(
            'Strategy',
            ['Vortos\\Secrets\\Value\\SecretValue'],
            'Strategy/ must never import SecretValue — only SecretReference',
        );
    }

    public function test_definition_does_not_import_secret_value(): void
    {
        $this->assertDirectoryFreeOf(
            'Definition',
            ['Vortos\\Secrets\\Value\\SecretValue'],
            'Definition/ must never import SecretValue',
        );
    }

    public function test_preflight_does_not_depend_on_console_or_runner(): void
    {
        $this->assertDirectoryFreeOf(
            'Preflight',
            ['Vortos\\Deploy\\Console\\', 'Vortos\\Deploy\\Runner\\'],
            'Preflight/ must not depend on Console/ or Runner/ (it is the lower layer)',
        );
    }

    public function test_runner_does_not_depend_on_console(): void
    {
        $this->assertDirectoryFreeOf(
            'Runner',
            ['Vortos\\Deploy\\Console\\', 'Symfony\\Component\\Console'],
            'Runner/ must be console-free (fully testable application layer)',
        );
    }

    public function test_console_namespace_does_not_leak_into_lower_layers(): void
    {
        foreach (['Preflight', 'Runner', 'Plan', 'Strategy', 'Definition', 'Rollback'] as $relDir) {
            $this->assertDirectoryFreeOf(
                $relDir,
                ['Vortos\\Deploy\\Console\\'],
                "Console/ must not leak into {$relDir}/",
            );
        }
    }

    /** @param list<string> $patterns */
    private function assertDirectoryFreeOf(string $relDir, array $patterns, string $message): void
    {
        $dir = dirname(__DIR__, 2) . '/' . $relDir;
        if (!is_dir($dir)) {
            $this->markTestSkipped($relDir . ' does not exist yet.');
        }

        $violations = [];

        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
        ) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $code = (string) file_get_contents($file->getPathname());
            foreach ($patterns as $pattern) {
                if (str_contains($code, $pattern)) {
                    $violations[] = basename($file->getPathname()) . ' depends on ' . $pattern;
                }
            }
        }

        $this->assertSame([], $violations, $message . ":\n  - " . implode("\n  - ", $violations));
    }
}
