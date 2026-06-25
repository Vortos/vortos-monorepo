<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class NoShellExecTest extends TestCase
{
    private const BANNED_PATTERNS = [
        'proc_open',
        'shell_exec',
        'passthru',
        'Process::fromShellCommandline',
    ];

    private const BANNED_REGEX = [
        '/(?<!\w)exec\s*\(/',
        '/`[^`]+`/',
    ];

    private const ALLOWED_FILES = [
        'ProcessCommandRunner.php',
        'RedisDeployStateStore.php',
    ];

    public function test_no_shell_exec_outside_runner(): void
    {
        $deployDir = dirname(__DIR__, 2);
        $violations = [];

        foreach ($this->phpFiles($deployDir) as $file) {
            $basename = basename($file);

            if (\in_array($basename, self::ALLOWED_FILES, true)) {
                continue;
            }

            if (str_contains($file, '/Tests/')) {
                continue;
            }

            $code = (string) file_get_contents($file);

            foreach (self::BANNED_PATTERNS as $pattern) {
                if (str_contains($code, $pattern)) {
                    $violations[] = $basename . ' uses banned pattern: ' . $pattern;
                }
            }

            foreach (self::BANNED_REGEX as $regex) {
                if (preg_match($regex, $code)) {
                    $violations[] = $basename . ' matches banned regex: ' . $regex;
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Shell exec primitives found outside ProcessCommandRunner:\n  - " . implode("\n  - ", $violations),
        );
    }

    public function test_process_command_runner_accepts_only_arrays(): void
    {
        $runnerFile = dirname(__DIR__, 2) . '/Execution/ProcessCommandRunner.php';
        $this->assertFileExists($runnerFile);

        $code = (string) file_get_contents($runnerFile);

        $this->assertStringNotContainsString(
            'fromShellCommandline',
            $code,
            'ProcessCommandRunner must not use Process::fromShellCommandline — argv arrays only.',
        );

        $this->assertStringNotContainsString(
            'shell_exec',
            $code,
            'ProcessCommandRunner must not use shell_exec.',
        );
    }

    /** @return list<string> */
    private function phpFiles(string $dir): array
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
        ) as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
