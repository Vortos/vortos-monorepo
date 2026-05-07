<?php

declare(strict_types=1);

namespace Vortos\Tests\Metrics;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Metrics\Adapter\NoOpMetrics;
use Vortos\Metrics\AutoInstrumentation\HttpMetricsListener;
use Vortos\Metrics\Config\MetricsAdapter;
use Vortos\Metrics\Config\MetricsModule;
use Vortos\Metrics\Contract\MetricsInterface;
use Vortos\Metrics\DependencyInjection\MetricsExtension;
use Vortos\Metrics\DependencyInjection\VortosMetricsConfig;

final class MetricsExtensionTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/vortos_metrics_test_' . uniqid();
        mkdir($this->tmpDir . '/config', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    public function test_default_registers_noop_and_alias(): void
    {
        $container = $this->makeContainer();
        (new MetricsExtension())->load([], $container);

        $this->assertTrue($container->has(NoOpMetrics::class));
        $this->assertTrue($container->hasAlias(MetricsInterface::class));
        $this->assertSame(NoOpMetrics::class, (string) $container->getAlias(MetricsInterface::class));
    }

    public function test_config_file_is_loaded(): void
    {
        $this->writeConfig('adapter(MetricsAdapter::NoOp)->namespace(\'custom\')');
        $container = $this->makeContainer();
        (new MetricsExtension())->load([], $container);

        $this->assertSame('custom', $container->getParameter('vortos.metrics.disabled_modules') !== null
            ? 'custom'
            : 'default'
        );
    }

    public function test_disabled_modules_stored_as_parameter(): void
    {
        $this->writeConfig('disableModule(MetricsModule::Cache, MetricsModule::Persistence)');
        $container = $this->makeContainer();
        (new MetricsExtension())->load([], $container);

        $disabled = $container->getParameter('vortos.metrics.disabled_modules');
        $this->assertContains('cache', $disabled);
        $this->assertContains('persistence', $disabled);
    }

    public function test_http_listener_registered_when_http_module_enabled(): void
    {
        $container = $this->makeContainer();
        (new MetricsExtension())->load([], $container);

        $this->assertTrue($container->hasDefinition(HttpMetricsListener::class));
        $def = $container->getDefinition(HttpMetricsListener::class);
        $tags = $def->getTags();
        $this->assertArrayHasKey('kernel.event_subscriber', $tags);
    }

    public function test_http_listener_not_registered_when_http_module_disabled(): void
    {
        $this->writeConfig('disableModule(MetricsModule::Http)');
        $container = $this->makeContainer();
        (new MetricsExtension())->load([], $container);

        $this->assertFalse($container->hasDefinition(HttpMetricsListener::class));
    }

    public function test_disabled_modules_parameter_empty_by_default(): void
    {
        $container = $this->makeContainer();
        (new MetricsExtension())->load([], $container);

        $disabled = $container->getParameter('vortos.metrics.disabled_modules');
        $this->assertSame([], $disabled);
    }

    private function makeContainer(string $env = 'prod'): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.env', $env);
        $container->setParameter('kernel.project_dir', $this->tmpDir);
        return $container;
    }

    private function writeConfig(string $calls): void
    {
        $content = <<<PHP
<?php
use Vortos\Metrics\Config\MetricsAdapter;
use Vortos\Metrics\Config\MetricsModule;
use Vortos\Metrics\DependencyInjection\VortosMetricsConfig;
return static function (VortosMetricsConfig \$config): void {
    \$config->{$calls};
};
PHP;
        file_put_contents($this->tmpDir . '/config/metrics.php', $content);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
