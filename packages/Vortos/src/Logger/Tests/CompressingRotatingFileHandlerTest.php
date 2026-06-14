<?php

declare(strict_types=1);

namespace Vortos\Logger\Tests;

use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Vortos\Logger\Handler\CompressingRotatingFileHandler;

final class CompressingRotatingFileHandlerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/vortos_compressing_handler_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->tmpDir);
    }

    public function test_rotated_file_is_gzip_compressed_and_removed(): void
    {
        $path = $this->tmpDir . '/app.log';

        // Pre-create a "yesterday" rotated file by writing through the handler
        // with a forced filename, then trigger rotate() directly.
        $oldFile = $this->tmpDir . '/app-2020-01-01.log';
        file_put_contents($oldFile, "old log line\n");

        $handler = new CompressingRotatingFileHandler($path, 14, Level::Debug);
        $handler->setFilenameFormat('{filename}-{date}', 'Y-m-d');

        $reflection = new \ReflectionMethod($handler, 'rotate');

        // Force the handler to believe it last wrote on 2020-01-01 so rotate()
        // treats app-2020-01-01.log as the "previous" file.
        $mustRotateProperty = new \ReflectionProperty($handler, 'mustRotate');
        $mustRotateProperty->setValue($handler, true);

        $urlProperty = new \ReflectionProperty($handler, 'url');
        $urlProperty->setValue($handler, $oldFile);

        $reflection->invoke($handler);

        $this->assertFileDoesNotExist($oldFile);
        $this->assertFileExists($oldFile . '.gz');

        $this->assertSame('old log line' . "\n", gzdecode((string) file_get_contents($oldFile . '.gz')));
    }

    public function test_already_compressed_files_are_left_alone(): void
    {
        $path = $this->tmpDir . '/app.log';
        $gzFile = $this->tmpDir . '/app-2020-01-01.log.gz';
        file_put_contents($gzFile, gzencode('already compressed'));

        $handler = new CompressingRotatingFileHandler($path, 14, Level::Debug);

        // compress() is only called by rotate() for non-.gz files; calling it
        // on an already-.gz path would double-encode, so rotate() guards this.
        // Here we assert the file is untouched when rotate() sees a .gz url.
        $urlProperty = new \ReflectionProperty($handler, 'url');
        $urlProperty->setValue($handler, $gzFile);

        $rotate = new \ReflectionMethod($handler, 'rotate');
        $rotate->invoke($handler);

        $this->assertFileExists($gzFile);
        $this->assertSame('already compressed', gzdecode((string) file_get_contents($gzFile)));
    }
}
