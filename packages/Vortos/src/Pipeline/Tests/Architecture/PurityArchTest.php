<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class PurityArchTest extends TestCase
{
    private const IO_PATTERNS = [
        'file_get_contents',
        'file_put_contents',
        'fopen',
        'fwrite',
        'fread',
        'curl_',
        'fsockopen',
        'stream_socket',
        'socket_',
        'proc_open',
        'exec(',
        'shell_exec',
        'system(',
        'passthru',
        'Symfony\\Component\\Process',
        'Symfony\\Component\\HttpClient',
        'Psr\\Http\\Client',
        'GuzzleHttp\\',
    ];

    /** @dataProvider pureDirectories */
    public function test_directory_has_no_io_symbols(string $dirName): void
    {
        $dir = dirname(__DIR__, 2) . '/' . $dirName;
        if (!is_dir($dir)) {
            $this->markTestSkipped($dirName . ' directory does not exist.');
        }

        $violations = [];

        foreach ($this->phpFiles($dir) as $file) {
            $code = (string) file_get_contents($file);
            foreach (self::IO_PATTERNS as $pattern) {
                if (str_contains($code, $pattern)) {
                    $violations[] = basename($file) . ' uses ' . $pattern;
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "{$dirName}/ must be pure (no I/O symbols):\n  - " . implode("\n  - ", $violations),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function pureDirectories(): iterable
    {
        yield 'Model' => ['Model'];
        yield 'Builder' => ['Builder'];
        yield 'Emitter' => ['Emitter'];
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
