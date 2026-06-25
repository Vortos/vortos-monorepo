<?php

declare(strict_types=1);

namespace Vortos\Iac\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class NoSecretInStateArchTest extends TestCase
{
    public function test_no_secret_literal_in_exporter_output(): void
    {
        $exporterDir = dirname(__DIR__, 2) . '/Exporter';
        $files = $this->phpFiles($exporterDir);

        foreach ($files as $file) {
            $content = file_get_contents($file);
            $this->assertStringNotContainsString(
                'getenv(',
                $content,
                sprintf('%s must not call getenv() — secrets flow through IacExecutionContext, never read from env in exporters.', basename($file)),
            );
        }
    }

    public function test_no_secret_written_to_tf_json_by_exporters(): void
    {
        $exporterDir = dirname(__DIR__, 2) . '/Exporter';
        $files = $this->phpFiles($exporterDir);

        foreach ($files as $file) {
            $content = file_get_contents($file);
            $this->assertStringNotContainsString(
                '->reveal()',
                $content,
                sprintf('%s must not call reveal() — exporters never touch plaintext secrets.', basename($file)),
            );
        }
    }

    public function test_no_secret_in_state_backend_exporter(): void
    {
        $file = dirname(__DIR__, 2) . '/Lifecycle/StateBackend/StateBackendExporter.php';
        $content = file_get_contents($file);
        $this->assertStringNotContainsString('->reveal()', $content);
        $this->assertStringNotContainsString('getenv(', $content);
        $this->assertStringNotContainsString('$_ENV', $content);
        $this->assertStringNotContainsString('$_SERVER', $content);
    }

    public function test_driver_only_reveals_into_env_array(): void
    {
        $engineFile = dirname(__DIR__, 2) . '/Driver/Terraform/TerraformEngine.php';
        $content = file_get_contents($engineFile);
        $revealCount = substr_count($content, '->reveal()');
        $this->assertSame(2, $revealCount, 'TerraformEngine should call reveal() exactly twice: buildEnv() and redact().');
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
