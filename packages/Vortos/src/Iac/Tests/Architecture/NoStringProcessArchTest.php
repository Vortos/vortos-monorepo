<?php

declare(strict_types=1);

namespace Vortos\Iac\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class NoStringProcessArchTest extends TestCase
{
    private const FORBIDDEN = [
        'proc_open',
        'shell_exec',
        '\\bexec\\s*\\(',
        '\\bsystem\\s*\\(',
        'passthru',
        '`',
    ];

    public function test_no_string_based_process_execution(): void
    {
        $driverDir = dirname(__DIR__, 2) . '/Driver/Terraform';
        $violations = [];

        foreach ($this->phpFiles($driverDir) as $file) {
            $contents = (string) file_get_contents($file);
            $relPath = str_replace(dirname(__DIR__, 2) . '/', '', $file);

            foreach (self::FORBIDDEN as $pattern) {
                if (str_contains($pattern, '\\b')) {
                    if (preg_match('/' . $pattern . '/i', $contents)) {
                        $violations[] = sprintf('%s: matches pattern %s', $relPath, $pattern);
                    }
                } elseif ($pattern === '`') {
                    if (preg_match('/`[^`]+`/', $contents)) {
                        $violations[] = sprintf('%s: uses backtick operator', $relPath);
                    }
                } elseif (stripos($contents, $pattern) !== false) {
                    $violations[] = sprintf('%s: contains %s', $relPath, $pattern);
                }
            }
        }

        $this->assertSame([], $violations,
            "Shell-injection vectors found in Driver/Terraform/:\n  - " . implode("\n  - ", $violations));
    }

    /** @return iterable<string> */
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
                yield $file->getPathname();
            }
        }
    }
}
