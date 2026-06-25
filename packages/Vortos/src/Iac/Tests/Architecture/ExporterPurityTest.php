<?php

declare(strict_types=1);

namespace Vortos\Iac\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class ExporterPurityTest extends TestCase
{
    private const IO_FUNCTIONS = [
        'file_get_contents',
        'file_put_contents',
        'fopen',
        'fwrite',
        'curl_',
        'stream_context',
        '$_ENV',
        '$_SERVER',
        'getenv',
    ];

    public function test_exporters_do_no_io(): void
    {
        $exporterDir = dirname(__DIR__, 2) . '/Exporter';
        $violations = [];

        foreach ($this->phpFiles($exporterDir) as $file) {
            $content = file_get_contents($file);
            foreach (self::IO_FUNCTIONS as $fn) {
                if (str_contains($content, $fn)) {
                    $violations[] = sprintf('%s uses %s', basename($file), $fn);
                }
            }
        }

        $this->assertSame([], $violations, "Exporters must be pure (no I/O):\n  " . implode("\n  ", $violations));
    }

    public function test_state_backend_exporter_is_pure(): void
    {
        $file = dirname(__DIR__, 2) . '/Lifecycle/StateBackend/StateBackendExporter.php';
        $content = file_get_contents($file);

        foreach (self::IO_FUNCTIONS as $fn) {
            $this->assertStringNotContainsString($fn, $content, sprintf('StateBackendExporter uses %s — must be pure.', $fn));
        }
    }

    /** @return list<string> */
    private function phpFiles(string $dir): array
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php' && !str_contains($file->getPathname(), '/Tests/')) {
                $files[] = $file->getPathname();
            }
        }
        return $files;
    }
}
