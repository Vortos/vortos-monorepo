<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class CleanArchTest extends TestCase
{
    public function test_model_does_not_depend_on_driver(): void
    {
        $this->assertDirectoryFreeOf(
            'Model',
            ['Vortos\\Pipeline\\Driver\\'],
            'Model/ must not depend on Driver/',
        );
    }

    public function test_model_does_not_depend_on_console(): void
    {
        $this->assertDirectoryFreeOf(
            'Model',
            ['Vortos\\Pipeline\\Console\\', 'Symfony\\Component\\Console'],
            'Model/ must not depend on Console/',
        );
    }

    public function test_model_does_not_depend_on_dependency_injection(): void
    {
        $this->assertDirectoryFreeOf(
            'Model',
            ['Vortos\\Pipeline\\DependencyInjection\\', 'Symfony\\Component\\DependencyInjection'],
            'Model/ must not depend on DependencyInjection/',
        );
    }

    public function test_emitter_port_does_not_depend_on_driver(): void
    {
        $this->assertDirectoryFreeOf(
            'Emitter',
            ['Vortos\\Pipeline\\Driver\\'],
            'Emitter/ (port) must not depend on Driver/',
        );
    }

    public function test_emitter_port_does_not_depend_on_console(): void
    {
        $this->assertDirectoryFreeOf(
            'Emitter',
            ['Vortos\\Pipeline\\Console\\', 'Symfony\\Component\\Console'],
            'Emitter/ (port) must not depend on Console/',
        );
    }

    public function test_builder_does_not_depend_on_driver(): void
    {
        $this->assertDirectoryFreeOf(
            'Builder',
            ['Vortos\\Pipeline\\Driver\\'],
            'Builder/ must not depend on Driver/',
        );
    }

    public function test_builder_does_not_depend_on_console(): void
    {
        $this->assertDirectoryFreeOf(
            'Builder',
            ['Vortos\\Pipeline\\Console\\', 'Symfony\\Component\\Console'],
            'Builder/ must not depend on Console/',
        );
    }

    public function test_builder_does_not_depend_on_dependency_injection(): void
    {
        $this->assertDirectoryFreeOf(
            'Builder',
            ['Vortos\\Pipeline\\DependencyInjection\\', 'Symfony\\Component\\DependencyInjection'],
            'Builder/ must not depend on DependencyInjection/',
        );
    }

    public function test_definition_does_not_depend_on_driver(): void
    {
        $this->assertDirectoryFreeOf(
            'Definition',
            ['Vortos\\Pipeline\\Driver\\'],
            'Definition/ must not depend on Driver/',
        );
    }

    public function test_definition_does_not_depend_on_console(): void
    {
        $this->assertDirectoryFreeOf(
            'Definition',
            ['Vortos\\Pipeline\\Console\\', 'Symfony\\Component\\Console'],
            'Definition/ must not depend on Console/',
        );
    }

    public function test_exception_does_not_depend_on_driver(): void
    {
        $this->assertDirectoryFreeOf(
            'Exception',
            ['Vortos\\Pipeline\\Driver\\'],
            'Exception/ must not depend on Driver/',
        );
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
