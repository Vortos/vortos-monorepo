<?php

declare(strict_types=1);

namespace Vortos\Observability\Collector;

use RuntimeException;
use Vortos\Observability\Sink\MetricsSinkRegistry;

/**
 * Renders the collector sidecar assets for the selected metrics sink and writes them
 * under `observability/collector/` in the project — mirroring the existing template
 * publisher's `--dry-run` / `--force` contract.
 *
 * Two artifacts are produced:
 *  - `otel-collector-config.yaml` — the rendered collector config (loopback receiver,
 *    bounded buffering, the sink's exporter);
 *  - `docker-compose.collector.yaml` — a sidecar fragment the operator merges in.
 *
 * Backend credentials are NEVER inlined: the sink's exporter references them via
 * `${env:...}` placeholders the collector resolves at runtime, so the committed YAML
 * carries no plaintext secret (§12.4 security).
 */
final class CollectorConfigPublisher
{
    private const CONFIG_FILE = 'observability/collector/otel-collector-config.yaml';
    private const COMPOSE_FILE = 'observability/collector/docker-compose.collector.yaml';

    public function __construct(
        private readonly MetricsSinkRegistry $registry,
        private readonly CollectorConfigBuilder $builder,
        private readonly YamlWriter $yaml = new YamlWriter(),
    ) {}

    public function publish(
        string $projectDir,
        string $sinkKey,
        CollectorBufferPolicy $policy,
        bool $force = false,
        bool $dryRun = false,
    ): CollectorPublishResult {
        $sink = $this->registry->sink($sinkKey);
        $config = $this->builder->build($sink, $policy);

        $artifacts = [
            self::CONFIG_FILE => $config->toYaml($this->yaml),
            self::COMPOSE_FILE => $this->composeFragment($policy),
        ];

        $written = [];
        $skipped = [];

        foreach ($artifacts as $relative => $contents) {
            $target = $projectDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);

            if (is_file($target) && hash('sha256', $contents) === hash_file('sha256', $target)) {
                $skipped[] = $relative;
                continue;
            }
            if (is_file($target) && !$force) {
                $skipped[] = $relative;
                continue;
            }

            if (!$dryRun) {
                $dir = dirname($target);
                if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                    throw new RuntimeException('Failed to create directory: ' . $dir);
                }
                if (file_put_contents($target, $contents, LOCK_EX) === false) {
                    throw new RuntimeException('Failed to write collector asset: ' . $target);
                }
            }

            $written[] = $relative;
        }

        return new CollectorPublishResult($written, $skipped);
    }

    private function composeFragment(CollectorBufferPolicy $policy): string
    {
        $fragment = [
            'services' => [
                'otel-collector' => [
                    'image' => 'otel/opentelemetry-collector-contrib:0.103.0',
                    'restart' => 'unless-stopped',
                    'command' => ['--config=/etc/otelcol/config.yaml'],
                    'volumes' => [
                        './observability/collector/otel-collector-config.yaml:/etc/otelcol/config.yaml:ro',
                        'vortos-otelcol-storage:' . $policy->storageDir,
                    ],
                    'ports' => ['127.0.0.1:4317:4317', '127.0.0.1:4318:4318'],
                ],
            ],
            'volumes' => [
                'vortos-otelcol-storage' => ['driver' => 'local'],
            ],
        ];

        return $this->yaml->dump($fragment);
    }
}
