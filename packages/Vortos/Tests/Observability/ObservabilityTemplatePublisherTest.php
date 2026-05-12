<?php

declare(strict_types=1);

namespace Vortos\Tests\Observability;

use PHPUnit\Framework\TestCase;
use Vortos\Observability\Service\ObservabilityTemplatePublisher;
use Vortos\Observability\Service\ObservabilityTemplateRegistry;

final class ObservabilityTemplatePublisherTest extends TestCase
{
    private string $tmpDir;
    private ObservabilityTemplatePublisher $publisher;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/vortos_observability_test_' . uniqid();
        mkdir($this->tmpDir, 0777, true);

        $this->publisher = new ObservabilityTemplatePublisher(
            new ObservabilityTemplateRegistry(__DIR__ . '/../../src/Observability/Resources/observability'),
        );
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    public function test_dry_run_does_not_write_files(): void
    {
        $result = $this->publisher->publish($this->tmpDir, 'prometheus', dryRun: true);

        $this->assertCount(2, $result->published);
        $this->assertFileDoesNotExist($this->tmpDir . '/observability/prometheus/vortos-alert-rules.yml');
    }

    public function test_publishes_stack_files(): void
    {
        $result = $this->publisher->publish($this->tmpDir, 'prometheus');

        $this->assertCount(2, $result->published);
        $this->assertFileExists($this->tmpDir . '/observability/prometheus/vortos-alert-rules.yml');
        $this->assertFileExists($this->tmpDir . '/observability/prometheus/vortos-recording-rules.yml');
    }

    public function test_does_not_overwrite_changed_files_without_force(): void
    {
        $this->publisher->publish($this->tmpDir, 'prometheus');
        $target = $this->tmpDir . '/observability/prometheus/vortos-alert-rules.yml';
        file_put_contents($target, 'local change');

        $result = $this->publisher->publish($this->tmpDir, 'prometheus');

        $this->assertContains('observability/prometheus/vortos-alert-rules.yml', $result->skipped);
        $this->assertSame('local change', file_get_contents($target));
    }

    public function test_force_overwrites_changed_files(): void
    {
        $this->publisher->publish($this->tmpDir, 'prometheus');
        $target = $this->tmpDir . '/observability/prometheus/vortos-alert-rules.yml';
        file_put_contents($target, 'local change');

        $result = $this->publisher->publish($this->tmpDir, 'prometheus', force: true);

        $this->assertContains('observability/prometheus/vortos-alert-rules.yml', $result->published);
        $this->assertStringContainsString('VortosHighHttpErrorRate', (string) file_get_contents($target));
    }

    public function test_unknown_stack_fails_cleanly(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->publisher->publish($this->tmpDir, 'unknown');
    }

    public function test_combined_grafana_oss_publishes_all_open_source_files(): void
    {
        $result = $this->publisher->publish($this->tmpDir, 'grafana-oss');

        $this->assertCount(4, $result->published);
        $this->assertFileExists($this->tmpDir . '/observability/prometheus/vortos-alert-rules.yml');
        $this->assertFileExists($this->tmpDir . '/observability/grafana/vortos-overview-dashboard.json');
        $this->assertFileExists($this->tmpDir . '/observability/alertmanager/vortos-alertmanager.yml');
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }

        rmdir($dir);
    }
}

