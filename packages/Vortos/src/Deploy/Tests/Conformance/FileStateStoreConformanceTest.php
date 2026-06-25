<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Conformance;

use Vortos\Deploy\Driver\LocalFile\FileDeployStateStore;
use Vortos\Deploy\State\DeployStateStoreInterface;
use Vortos\Deploy\Testing\DeployStateStoreConformanceTestCase;

final class FileStateStoreConformanceTest extends DeployStateStoreConformanceTestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/vortos-conformance-state-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tempDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($items as $item) {
                if ($item->isDir()) {
                    rmdir($item->getPathname());
                } else {
                    unlink($item->getPathname());
                }
            }

            rmdir($this->tempDir);
        }
    }

    protected function createStore(): DeployStateStoreInterface
    {
        return new FileDeployStateStore($this->tempDir);
    }
}
