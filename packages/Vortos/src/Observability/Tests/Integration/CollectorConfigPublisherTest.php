<?php

declare(strict_types=1);

namespace Vortos\Observability\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Vortos\Observability\Collector\CollectorBufferPolicy;
use Vortos\Observability\Collector\CollectorConfigBuilder;
use Vortos\Observability\Collector\CollectorConfigPublisher;
use Vortos\Observability\Driver\GrafanaOtlp\GrafanaOtlpMetricsSink;
use Vortos\Observability\Sink\MetricsSinkRegistry;

final class CollectorConfigPublisherTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/vortos-publish-' . bin2hex(random_bytes(6));
        mkdir($this->projectDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->projectDir);
    }

    private function publisher(): CollectorConfigPublisher
    {
        $registry = new MetricsSinkRegistry(new ServiceLocator([
            'grafana' => static fn () => new GrafanaOtlpMetricsSink('collector.example.com'),
        ]));

        return new CollectorConfigPublisher($registry, new CollectorConfigBuilder());
    }

    public function test_dry_run_writes_nothing(): void
    {
        $result = $this->publisher()->publish($this->projectDir, 'grafana', new CollectorBufferPolicy(), false, true);

        self::assertNotEmpty($result->written);
        self::assertFileDoesNotExist($this->projectDir . '/observability/collector/otel-collector-config.yaml');
    }

    public function test_writes_config_and_compose_fragment(): void
    {
        $this->publisher()->publish($this->projectDir, 'grafana', new CollectorBufferPolicy());

        self::assertFileExists($this->projectDir . '/observability/collector/otel-collector-config.yaml');
        self::assertFileExists($this->projectDir . '/observability/collector/docker-compose.collector.yaml');
    }

    public function test_idempotent_second_publish_skips_unchanged(): void
    {
        $publisher = $this->publisher();
        $publisher->publish($this->projectDir, 'grafana', new CollectorBufferPolicy());
        $result = $publisher->publish($this->projectDir, 'grafana', new CollectorBufferPolicy());

        self::assertSame([], $result->written);
        self::assertNotEmpty($result->skipped);
    }

    public function test_existing_file_not_overwritten_without_force(): void
    {
        $target = $this->projectDir . '/observability/collector/otel-collector-config.yaml';
        mkdir(dirname($target), 0755, true);
        file_put_contents($target, 'hand-edited');

        $result = $this->publisher()->publish($this->projectDir, 'grafana', new CollectorBufferPolicy(), false, false);

        self::assertContains('observability/collector/otel-collector-config.yaml', $result->skipped);
        self::assertSame('hand-edited', file_get_contents($target));
    }

    public function test_force_overwrites(): void
    {
        $target = $this->projectDir . '/observability/collector/otel-collector-config.yaml';
        mkdir(dirname($target), 0755, true);
        file_put_contents($target, 'hand-edited');

        $this->publisher()->publish($this->projectDir, 'grafana', new CollectorBufferPolicy(), true, false);

        self::assertNotSame('hand-edited', file_get_contents($target));
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
