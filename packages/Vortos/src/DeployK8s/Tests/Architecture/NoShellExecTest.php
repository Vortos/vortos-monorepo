<?php

declare(strict_types=1);

namespace Vortos\DeployK8s\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class NoShellExecTest extends TestCase
{
    private const FORBIDDEN_FUNCTIONS = [
        'shell_exec',
        'exec(',
        'passthru',
        'proc_open',
        'system(',
        'popen(',
    ];

    public function test_no_shell_execution_in_package(): void
    {
        $packagePath = \dirname(__DIR__, 2);

        $violations = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($packagePath, \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            // Skip test files
            if (str_contains($file->getPathname(), '/Tests/')) {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            if ($contents === false) {
                continue;
            }

            foreach (self::FORBIDDEN_FUNCTIONS as $fn) {
                if (str_contains($contents, $fn)) {
                    $violations[] = sprintf('%s — contains "%s"', $file->getPathname(), $fn);
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            sprintf(
                "DeployK8s must use argv-only execution, never shell strings.\nViolations:\n%s",
                implode("\n", $violations),
            ),
        );
    }
}
