<?php

declare(strict_types=1);

namespace Vortos\Observability\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Vortos\Observability\Collector\CollectorConfigBuilder;
use Vortos\Observability\Collector\CollectorConfigPublisher;
use Vortos\Observability\Command\GenerateCollectorConfigCommand;
use Vortos\Observability\Driver\GrafanaOtlp\GrafanaOtlpMetricsSink;
use Vortos\Observability\Sink\MetricsSinkRegistry;

/**
 * End-to-end coverage of the `vortos:observability:collector` command's log-aggregation wiring:
 * logs ride the sidecar by default, and `--no-logs` opts out.
 */
final class GenerateCollectorConfigCommandTest extends TestCase
{
    private string $projectDir;
    private string $previousCwd;

    protected function setUp(): void
    {
        $this->previousCwd = (string) getcwd();
        $this->projectDir = sys_get_temp_dir() . '/vortos-collector-cmd-' . bin2hex(random_bytes(6));
        mkdir($this->projectDir, 0755, true);
        chdir($this->projectDir);
    }

    protected function tearDown(): void
    {
        chdir($this->previousCwd);
        $this->rmrf($this->projectDir);
    }

    private function tester(): CommandTester
    {
        $registry = new MetricsSinkRegistry(new ServiceLocator([
            'grafana' => static fn () => new GrafanaOtlpMetricsSink('otlp.grafana.net', basePath: '/otlp'),
        ]));
        $publisher = new CollectorConfigPublisher($registry, new CollectorConfigBuilder());

        return new CommandTester(new GenerateCollectorConfigCommand($publisher, 'grafana'));
    }

    private function config(): string
    {
        return (string) file_get_contents($this->projectDir . '/observability/collector/otel-collector-config.yaml');
    }

    public function test_logs_pipeline_generated_by_default(): void
    {
        $exit = $this->tester()->execute([]);

        self::assertSame(0, $exit);
        $config = $this->config();
        self::assertStringContainsString('filelog/vortos:', $config);
        self::assertStringContainsString('otlphttp/vortos_logs:', $config);
        // The exporter endpoint is the clean Grafana Cloud form — no stray :4318.
        self::assertStringContainsString('https://otlp.grafana.net/otlp', $config);
        self::assertStringNotContainsString(':4318/otlp', $config);
    }

    public function test_no_logs_flag_omits_the_logs_pipeline(): void
    {
        $exit = $this->tester()->execute(['--no-logs' => true]);

        self::assertSame(0, $exit);
        self::assertStringNotContainsString('filelog/vortos', $this->config());
    }

    public function test_custom_sample_and_include_are_honored(): void
    {
        $exit = $this->tester()->execute([
            '--log-sample' => '0.5',
            '--log-include' => ['/var/log/app/*.json'],
        ]);

        self::assertSame(0, $exit);
        $config = $this->config();
        self::assertStringContainsString('/var/log/app/*.json', $config);
        self::assertStringContainsString('probabilistic_sampler/vortos_logs', $config);
    }

    private function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
