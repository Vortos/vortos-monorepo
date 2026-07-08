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

    public function test_compose_fragment_chowns_storage_volume_before_collector_starts(): void
    {
        // B13: the persistent-queue named volume is root-owned on first create, but the collector
        // runs as uid 10001 and crash-loops on `permission denied`. The generated compose must ship
        // an init sidecar that chowns the volume, and the collector must wait for it.
        $this->publisher()->publish($this->projectDir, 'grafana', new CollectorBufferPolicy());

        $compose = (string) file_get_contents(
            $this->projectDir . '/observability/collector/docker-compose.collector.yaml',
        );

        // Init sidecar chowns the volume, runs as root, and is a one-shot.
        self::assertStringContainsString('otel-collector-init:', $compose);
        self::assertStringContainsString('user: 0:0', $compose);
        // Quoted deliberately: bare `no` is YAML-1.1 boolean false — the writer keeps it a string.
        self::assertStringContainsString('restart: "no"', $compose);
        self::assertStringContainsString('chown -R 10001:10001', $compose);

        // Collector pins its uid and waits for the init sidecar to finish.
        self::assertStringContainsString('user: 10001:10001', $compose);
        self::assertStringContainsString('service_completed_successfully', $compose);
    }

    public function test_host_metrics_adds_hostfs_mount_to_collector_service(): void
    {
        $this->publisher()->publish($this->projectDir, 'grafana', new CollectorBufferPolicy(hostMetrics: true));

        $compose = (string) file_get_contents(
            $this->projectDir . '/observability/collector/docker-compose.collector.yaml',
        );

        self::assertStringContainsString('/:/hostfs:ro', $compose);
    }

    public function test_no_hostfs_mount_without_host_metrics(): void
    {
        $this->publisher()->publish($this->projectDir, 'grafana', new CollectorBufferPolicy());

        $compose = (string) file_get_contents(
            $this->projectDir . '/observability/collector/docker-compose.collector.yaml',
        );

        self::assertStringNotContainsString('/hostfs', $compose);
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
