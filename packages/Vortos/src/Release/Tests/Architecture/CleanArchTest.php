<?php

declare(strict_types=1);

namespace Vortos\Release\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class CleanArchTest extends TestCase
{
    public function test_schema_vos_depend_on_nothing_infra(): void
    {
        $schemaDir = dirname(__DIR__, 2) . '/Schema';
        $infraPatterns = [
            'Doctrine\\',
            'Symfony\\',
            'use Vortos\\Release\\ReadModel\\',
            'use Vortos\\Release\\Migration\\Doctrine',
        ];

        $violations = [];

        foreach (glob($schemaDir . '/*.php') as $file) {
            $code = file_get_contents($file);
            foreach ($infraPatterns as $pattern) {
                if (str_contains($code, $pattern)) {
                    $violations[] = basename($file) . ' depends on ' . $pattern;
                }
            }
        }

        $this->assertSame([], $violations, "Schema VOs must not depend on infrastructure:\n  - " . implode("\n  - ", $violations));
    }

    public function test_manifest_vos_depend_on_nothing_infra(): void
    {
        $manifestDir = dirname(__DIR__, 2) . '/Manifest';
        $infraPatterns = [
            'Doctrine\\',
            'Symfony\\',
            'use Vortos\\Release\\ReadModel\\',
            'use Vortos\\Release\\Migration\\Doctrine',
        ];

        $violations = [];

        foreach (glob($manifestDir . '/*.php') as $file) {
            $code = file_get_contents($file);
            foreach ($infraPatterns as $pattern) {
                if (str_contains($code, $pattern)) {
                    $violations[] = basename($file) . ' depends on ' . $pattern;
                }
            }
        }

        $this->assertSame([], $violations, "Manifest VOs must not depend on infrastructure:\n  - " . implode("\n  - ", $violations));
    }

    public function test_port_interfaces_depend_on_nothing_infra(): void
    {
        $files = [
            dirname(__DIR__, 2) . '/Migration/AppliedMigrationSetReaderInterface.php',
            dirname(__DIR__, 2) . '/ReadModel/ManifestRepositoryInterface.php',
            dirname(__DIR__, 2) . '/ReadModel/ManifestReadModelInterface.php',
        ];

        $infraPatterns = ['Doctrine\\', 'Symfony\\'];

        $violations = [];

        foreach ($files as $file) {
            if (!file_exists($file)) {
                continue;
            }
            $code = file_get_contents($file);
            foreach ($infraPatterns as $pattern) {
                if (str_contains($code, $pattern)) {
                    $violations[] = basename($file) . ' depends on ' . $pattern;
                }
            }
        }

        $this->assertSame([], $violations, "Port interfaces must not depend on infrastructure:\n  - " . implode("\n  - ", $violations));
    }

    public function test_version_vos_depend_on_nothing_infra(): void
    {
        $versionDir = dirname(__DIR__, 2) . '/Version';
        $infraPatterns = [
            'Doctrine\\',
            'Symfony\\Component\\Process',
            'use Vortos\\Release\\ReadModel\\',
            'use Vortos\\Release\\Git\\Process\\',
        ];

        $violations = [];

        foreach (glob($versionDir . '/*.php') as $file) {
            $code = (string) file_get_contents($file);
            foreach ($infraPatterns as $pattern) {
                if (str_contains($code, $pattern)) {
                    $violations[] = basename($file) . ' depends on ' . $pattern;
                }
            }
        }

        $this->assertSame([], $violations, "Version VOs must not depend on infrastructure:\n  - " . implode("\n  - ", $violations));
    }

    public function test_changelog_vos_depend_on_nothing_infra(): void
    {
        $changelogDir = dirname(__DIR__, 2) . '/Changelog';
        $infraPatterns = [
            'Doctrine\\',
            'Symfony\\Component\\Process',
            'use Vortos\\Release\\Git\\Process\\',
        ];

        $violations = [];

        foreach (glob($changelogDir . '/*.php') as $file) {
            $code = (string) file_get_contents($file);
            foreach ($infraPatterns as $pattern) {
                if (str_contains($code, $pattern)) {
                    $violations[] = basename($file) . ' depends on ' . $pattern;
                }
            }
        }

        $this->assertSame([], $violations, "Changelog VOs must not depend on infrastructure:\n  - " . implode("\n  - ", $violations));
    }

    public function test_plan_vos_depend_on_nothing_infra(): void
    {
        $planDir = dirname(__DIR__, 2) . '/Plan';
        $infraPatterns = [
            'Doctrine\\',
            'Symfony\\Component\\Process',
            'use Vortos\\Release\\Git\\Process\\',
        ];

        $violations = [];

        foreach (glob($planDir . '/*.php') as $file) {
            $code = (string) file_get_contents($file);
            foreach ($infraPatterns as $pattern) {
                if (str_contains($code, $pattern)) {
                    $violations[] = basename($file) . ' depends on ' . $pattern;
                }
            }
        }

        $this->assertSame([], $violations, "Plan VOs must not depend on infrastructure:\n  - " . implode("\n  - ", $violations));
    }

    public function test_git_port_interface_depends_on_nothing_infra(): void
    {
        $file = dirname(__DIR__, 2) . '/Git/GitRepositoryInterface.php';
        $code = (string) file_get_contents($file);

        $this->assertStringNotContainsString('Symfony\\Component\\Process', $code);
        $this->assertStringNotContainsString('Doctrine\\', $code);
    }

    public function test_git_io_confined_to_process_namespace(): void
    {
        $releaseDir = dirname(__DIR__, 2);
        $violations = [];

        $dirsToScan = ['Version', 'Changelog', 'Plan', 'Schema', 'Manifest', 'Service'];

        foreach ($dirsToScan as $dir) {
            $path = $releaseDir . '/' . $dir;
            if (!is_dir($path)) {
                continue;
            }
            foreach (glob($path . '/*.php') as $file) {
                $code = (string) file_get_contents($file);
                if (str_contains($code, 'Symfony\\Component\\Process\\Process')) {
                    $violations[] = basename($file) . ' in ' . $dir . '/ uses Process directly';
                }
            }
        }

        $this->assertSame([], $violations, "Only Git/Process/ may use symfony/process:\n  - " . implode("\n  - ", $violations));
    }
}
