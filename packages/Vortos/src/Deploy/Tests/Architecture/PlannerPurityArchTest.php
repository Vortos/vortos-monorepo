<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class PlannerPurityArchTest extends TestCase
{
    private const IO_PATTERNS = [
        'Symfony\\Component\\Process',
        'Symfony\\Component\\HttpClient',
        'Symfony\\Component\\HttpFoundation',
        'Psr\\Http\\',
        'GuzzleHttp\\',
        'ssh2_',
        'PDO',
        'Doctrine\\DBAL',
        'Doctrine\\ORM',
        'microtime',
        'time()',
        'hrtime',
        'file_get_contents',
        'file_put_contents',
        'fopen',
        'fwrite',
        'fread',
        'curl_',
        'socket_',
        'fsockopen',
        'stream_socket',
        'proc_open',
        'exec(',
        'shell_exec',
        'system(',
        'passthru',
        'Http\\',
    ];

    public function test_plan_namespace_imports_no_io_symbol(): void
    {
        $planDir = dirname(__DIR__, 2) . '/Plan';
        $this->assertDirectoryExists($planDir);

        $violations = [];

        foreach ($this->phpFiles($planDir) as $file) {
            $code = (string) file_get_contents($file);
            foreach (self::IO_PATTERNS as $pattern) {
                if (str_contains($code, $pattern)) {
                    $violations[] = basename($file) . ' imports/uses ' . $pattern;
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Plan/ namespace must be pure (no I/O symbols):\n  - " . implode("\n  - ", $violations),
        );
    }

    public function test_strategy_namespace_imports_no_io_symbol(): void
    {
        $strategyDir = dirname(__DIR__, 2) . '/Strategy';
        $this->assertDirectoryExists($strategyDir);

        $violations = [];

        foreach ($this->phpFiles($strategyDir) as $file) {
            $code = (string) file_get_contents($file);
            foreach (self::IO_PATTERNS as $pattern) {
                if (str_contains($code, $pattern)) {
                    $violations[] = basename($file) . ' imports/uses ' . $pattern;
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Strategy/ namespace must be pure (no I/O symbols):\n  - " . implode("\n  - ", $violations),
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
