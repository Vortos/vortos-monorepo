<?php

declare(strict_types=1);

namespace Vortos\OpsKit\Tests\Console;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\OpsKit\Console\DriverScaffolder;
use Vortos\OpsKit\Console\MakeDriverCommand;

final class MakeDriverCommandTest extends TestCase
{
    private string $dir;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/vortos-ops-kit-make-' . uniqid();
        mkdir($this->dir, 0755, true);
        $this->tester = new CommandTester(new MakeDriverCommand(new DriverScaffolder($this->dir)));
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->dir);
    }

    public function test_generates_driver_and_conformance_test(): void
    {
        $status = $this->tester->execute(['concern' => 'deploy', 'name' => 'k8s', '--dir' => $this->dir]);
        $this->assertSame(Command::SUCCESS, $status);

        $driver = $this->dir . '/K8s/K8sDriver.php';
        $test   = $this->dir . '/K8s/Tests/K8sDriverConformanceTest.php';
        $this->assertFileExists($driver);
        $this->assertFileExists($test);

        $driverCode = (string) file_get_contents($driver);
        $this->assertStringContainsString('namespace App\\Deploy\\Driver\\K8s;', $driverCode);
        $this->assertStringContainsString("#[AsDriver(key: 'k8s')]", $driverCode);
        $this->assertStringContainsString('implements DriverInterface', $driverCode);
        $this->assertStringContainsString('public function capabilities(): CapabilityDescriptor', $driverCode);
        $this->assertValidPhp($driverCode);

        $testCode = (string) file_get_contents($test);
        $this->assertStringContainsString('extends ConformanceTestCase', $testCode);
        $this->assertStringContainsString('return new K8sDriver();', $testCode);
        $this->assertStringContainsString("return 'k8s';", $testCode);
        $this->assertValidPhp($testCode);
    }

    public function test_split_generates_valid_composer_and_di(): void
    {
        $this->tester->execute(['concern' => 'deploy', 'name' => 'k8s', '--dir' => $this->dir, '--split' => true]);

        $composerPath = $this->dir . '/K8s/composer.json';
        $this->assertFileExists($composerPath);
        $manifest = json_decode((string) file_get_contents($composerPath), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('vortos/vortos-deploy-k8s', $manifest['name']);
        $this->assertSame('^1.0', $manifest['require']['vortos/vortos-ops-kit']);
        $this->assertSame('^1.0', $manifest['require']['vortos/vortos-deploy']);
        $this->assertSame(150, $manifest['extra']['vortos']['order']);
        $this->assertSame('App\\Deploy\\Driver\\K8s\\DependencyInjection\\K8sPackage', $manifest['extra']['vortos']['package']);
        $this->assertArrayHasKey('App\\Deploy\\Driver\\K8s\\', $manifest['autoload']['psr-4']);

        $package   = $this->dir . '/K8s/DependencyInjection/K8sPackage.php';
        $extension = $this->dir . '/K8s/DependencyInjection/K8sExtension.php';
        $this->assertFileExists($package);
        $this->assertFileExists($extension);
        $this->assertValidPhp((string) file_get_contents($package));

        $extensionCode = (string) file_get_contents($extension);
        $this->assertStringContainsString("addTag('vortos.deploy.driver')", $extensionCode);
        $this->assertStringContainsString("return 'vortos_deploy_k8s';", $extensionCode);
        $this->assertValidPhp($extensionCode);
    }

    public function test_custom_namespace_and_order_are_honored(): void
    {
        $this->tester->execute([
            'concern' => 'deploy',
            'name' => 'blue-green',
            '--dir' => $this->dir,
            '--namespace' => 'Vortos\\Deploy\\Driver',
            '--order' => '151',
            '--split' => true,
        ]);

        $driver = $this->dir . '/BlueGreen/BlueGreenDriver.php';
        $this->assertFileExists($driver);
        $this->assertStringContainsString('namespace Vortos\\Deploy\\Driver\\BlueGreen;', (string) file_get_contents($driver));

        $manifest = json_decode((string) file_get_contents($this->dir . '/BlueGreen/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('vortos/vortos-deploy-blue-green', $manifest['name']);
        $this->assertSame(151, $manifest['extra']['vortos']['order']);
    }

    public function test_invalid_concern_fails(): void
    {
        $status = $this->tester->execute(['concern' => 'Deploy', 'name' => 'k8s', '--dir' => $this->dir]);
        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('Invalid concern', $this->tester->getDisplay());
    }

    public function test_invalid_name_fails(): void
    {
        $status = $this->tester->execute(['concern' => 'deploy', 'name' => 'K8S', '--dir' => $this->dir]);
        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('Invalid driver name', $this->tester->getDisplay());
    }

    public function test_invalid_order_fails(): void
    {
        $status = $this->tester->execute(['concern' => 'deploy', 'name' => 'k8s', '--dir' => $this->dir, '--order' => 'abc']);
        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('--order must be an integer', $this->tester->getDisplay());
    }

    public function test_existing_file_is_skipped_not_overwritten(): void
    {
        $this->tester->execute(['concern' => 'deploy', 'name' => 'k8s', '--dir' => $this->dir]);
        $driver = $this->dir . '/K8s/K8sDriver.php';
        file_put_contents($driver, '<?php // hand-edited');

        $this->tester->execute(['concern' => 'deploy', 'name' => 'k8s', '--dir' => $this->dir]);
        $this->assertStringContainsString('// hand-edited', (string) file_get_contents($driver));
        $this->assertStringContainsString('skipped', $this->tester->getDisplay());
    }

    private function assertValidPhp(string $code): void
    {
        try {
            $this->assertNotSame([], token_get_all($code, \TOKEN_PARSE));
        } catch (\ParseError $e) {
            $this->fail('Generated PHP has a syntax error: ' . $e->getMessage());
        }
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
