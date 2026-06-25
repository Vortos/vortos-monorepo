<?php

declare(strict_types=1);

namespace Vortos\Release\Tests\Service;

use PHPUnit\Framework\TestCase;
use Vortos\Release\Service\PackageDiscovery;

final class PackageDiscoveryTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/vortos-pkg-disc-' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->cleanup($this->tempDir);
    }

    public function test_discovers_vortos_packages(): void
    {
        $this->createPackage('PkgA', 'vortos/vortos-a', 100);
        $this->createPackage('PkgB', 'vortos/vortos-b', 200);

        $discovery = new PackageDiscovery($this->tempDir);
        $packages = $discovery->discover();

        $this->assertCount(2, $packages);
        $this->assertSame('vortos/vortos-a', $packages[0]->name);
        $this->assertSame('vortos/vortos-b', $packages[1]->name);
    }

    public function test_ignores_non_vortos_packages(): void
    {
        $this->createPackage('Other', 'someone/other-pkg', 100);
        $this->createPackage('VortosA', 'vortos/vortos-a', 100);

        $discovery = new PackageDiscovery($this->tempDir);
        $packages = $discovery->discover();

        $this->assertCount(1, $packages);
        $this->assertSame('vortos/vortos-a', $packages[0]->name);
    }

    public function test_sorted_by_order(): void
    {
        $this->createPackage('PkgC', 'vortos/vortos-c', 300);
        $this->createPackage('PkgA', 'vortos/vortos-a', 100);
        $this->createPackage('PkgB', 'vortos/vortos-b', 200);

        $discovery = new PackageDiscovery($this->tempDir);
        $packages = $discovery->discover();

        $this->assertSame('vortos/vortos-a', $packages[0]->name);
        $this->assertSame('vortos/vortos-b', $packages[1]->name);
        $this->assertSame('vortos/vortos-c', $packages[2]->name);
    }

    public function test_empty_directory(): void
    {
        $discovery = new PackageDiscovery($this->tempDir);
        $packages = $discovery->discover();
        $this->assertSame([], $packages);
    }

    public function test_package_without_order(): void
    {
        $dir = $this->tempDir . '/NoOrder';
        mkdir($dir, 0o755, true);
        file_put_contents($dir . '/composer.json', json_encode(['name' => 'vortos/vortos-no-order']));

        $discovery = new PackageDiscovery($this->tempDir);
        $packages = $discovery->discover();

        $this->assertCount(1, $packages);
        $this->assertSame(0, $packages[0]->order);
    }

    private function createPackage(string $dirName, string $name, int $order): void
    {
        $dir = $this->tempDir . '/' . $dirName;
        mkdir($dir, 0o755, true);

        $data = [
            'name' => $name,
            'extra' => ['vortos' => ['order' => $order]],
        ];

        file_put_contents($dir . '/composer.json', json_encode($data));
    }

    private function cleanup(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (glob($dir . '/*') ?: [] as $file) {
            is_dir($file) ? $this->cleanup($file) : unlink($file);
        }

        rmdir($dir);
    }
}
