<?php

declare(strict_types=1);

namespace Vortos\FeatureFlagsAdmin\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Architecture tripwire: every public #[Route] action in the admin package MUST call
 * requirePermission() as its first line. Mirrors the WriteBoundaryTest in the engine.
 */
final class AdminAuthzGateTripwireTest extends TestCase
{
    public function test_every_admin_route_calls_require_permission(): void
    {
        $packageDir = dirname(__DIR__, 2);
        $controllerDirs = [
            $packageDir . '/Http/Controller',
            $packageDir . '/Http/Fragment',
        ];

        $violations = [];

        foreach ($controllerDirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            foreach ($this->phpFiles($dir) as $file) {
                $source = (string) file_get_contents($file->getPathname());

                if (!str_contains($source, '#[Route(')) {
                    continue;
                }

                preg_match_all(
                    '/public\s+function\s+(\w+)\s*\([^)]*\)\s*:\s*\w+\s*\{(.*?)(?=public\s+function|\}\s*\z)/s',
                    $source,
                    $matches,
                    PREG_SET_ORDER,
                );

                foreach ($matches as $match) {
                    $methodName = $match[1];
                    $methodBody = $match[2];

                    if ($methodName === '__construct') {
                        continue;
                    }

                    $hasRouteAttr = (bool) preg_match(
                        '/#\[Route\(.*?\)\]\s*public\s+function\s+' . preg_quote($methodName) . '\b/',
                        $source,
                    );

                    if (!$hasRouteAttr) {
                        continue;
                    }

                    if (!str_contains($methodBody, 'requirePermission(')) {
                        $relative = str_replace($packageDir . '/', '', $file->getPathname());
                        $violations[] = "{$relative}::{$methodName}()";
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "These admin routes do not call requirePermission() — every route must be RBAC-gated:\n  - "
            . implode("\n  - ", $violations),
        );
    }

    public function test_admin_controllers_never_call_flag_storage_mutators_directly(): void
    {
        $packageDir = dirname(__DIR__, 2);
        $violations = [];

        foreach ($this->phpFiles($packageDir . '/Http') as $file) {
            $source = (string) file_get_contents($file->getPathname());

            if (!str_contains($source, 'FlagStorageInterface')) {
                continue;
            }

            if (preg_match('/->\s*save\s*\(/', $source) || preg_match('/->\s*delete\s*\(/', $source)) {
                $relative = str_replace($packageDir . '/', '', $file->getPathname());
                $violations[] = $relative;
            }
        }

        $this->assertSame(
            [],
            $violations,
            "These admin controllers call flag storage mutators directly — route through FlagWriteService:\n  - "
            . implode("\n  - ", $violations),
        );
    }

    public function test_admin_controllers_do_not_inject_http_client(): void
    {
        $packageDir = dirname(__DIR__, 2);
        $violations = [];

        foreach ($this->phpFiles($packageDir . '/Http') as $file) {
            $source = (string) file_get_contents($file->getPathname());
            $relative = str_replace($packageDir . '/', '', $file->getPathname());

            if (str_contains($source, 'HttpClient') || str_contains($source, 'GuzzleHttp')) {
                $violations[] = $relative;
            }
        }

        $this->assertSame(
            [],
            $violations,
            "These admin controllers inject an HTTP client (self-HTTP is forbidden):\n  - "
            . implode("\n  - ", $violations),
        );
    }

    /** @return iterable<\SplFileInfo> */
    private function phpFiles(string $dir): iterable
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                yield $file;
            }
        }
    }
}
