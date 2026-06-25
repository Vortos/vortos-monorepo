<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\State;

use Vortos\Deploy\Driver\LocalFile\FileDeployStateStore;
use Vortos\Deploy\State\DeployStateStoreInterface;
use Vortos\Deploy\Testing\DeployStateStoreConformanceTestCase;

final class FileDeployStateStoreTest extends DeployStateStoreConformanceTestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/vortos-test-state-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $this->removeDir($this->tempDir);
        }
    }

    protected function createStore(): DeployStateStoreInterface
    {
        return new FileDeployStateStore($this->tempDir);
    }

    public function test_creates_directory_on_construction(): void
    {
        $this->assertDirectoryDoesNotExist($this->tempDir);
        new FileDeployStateStore($this->tempDir);
        $this->assertDirectoryExists($this->tempDir);
    }

    private function removeDir(string $dir): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }
}
