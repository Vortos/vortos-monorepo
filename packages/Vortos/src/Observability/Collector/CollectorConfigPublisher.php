<?php

declare(strict_types=1);

namespace Vortos\Observability\Collector;

use RuntimeException;
use Vortos\Observability\Sink\MetricsSinkRegistry;
use Vortos\Observability\Sink\TelemetrySignal;

/**
 * Renders the collector sidecar assets for the selected metrics sink and writes them
 * under `observability/collector/` in the project — mirroring the existing template
 * publisher's `--dry-run` / `--force` contract.
 *
 * Two artifacts are produced:
 *  - `otel-collector-config.yaml` — the rendered collector config (loopback receiver,
 *    bounded buffering, the sink's metrics/traces exporter, and — when the sink carries
 *    the Logs signal and log aggregation is enabled — a filelog logs pipeline);
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
        private readonly LogPipelineBuilder $logPipelineBuilder = new LogPipelineBuilder(),
        private readonly YamlWriter $yaml = new YamlWriter(),
    ) {}

    public function publish(
        string $projectDir,
        string $sinkKey,
        CollectorBufferPolicy $policy,
        bool $force = false,
        bool $dryRun = false,
        string $receiverHost = CollectorConfigBuilder::DEFAULT_RECEIVER_HOST,
        bool $logs = true,
        ?LogPipelineConfig $logConfig = null,
    ): CollectorPublishResult {
        $sink = $this->registry->sink($sinkKey);
        $config = $this->builder->build($sink, $policy, $receiverHost);

        // Log aggregation is on by default, but only takes effect when the selected sink
        // actually accepts logs — a metrics-only sink (e.g. `null`) never grows a logs pipeline.
        $logsActive = $logs && in_array(TelemetrySignal::Logs, $sink->signals(), true);
        $logConfig ??= new LogPipelineConfig(storageDir: $policy->storageDir);

        if ($logsActive) {
            $exporter = $sink->exporterConfig();
            $config = $this->logPipelineBuilder->merge($config, $logConfig, [
                'type' => $exporter->type,
                // Raw endpoint/headers/tls only — LogPipelineBuilder layers on the logs-specific
                // persistent queue + retry so log delivery is isolated from metrics/traces.
                'settings' => $exporter->settings,
            ]);
        }

        $artifacts = [
            self::CONFIG_FILE => $config->toYaml($this->yaml),
            self::COMPOSE_FILE => $this->composeFragment($policy, $logsActive, $logConfig),
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

    /**
     * The uid the otel/opentelemetry-collector-contrib image runs as when NOT tailing container
     * logs. A freshly-created named volume is root-owned (0755), so the nonroot collector cannot
     * write its persistent queue there and the container crash-loops on boot (`permission denied`
     * opening the file_storage dir). An init sidecar chowns the volume to this uid before start.
     *
     * When log aggregation is enabled the collector must instead read Docker's container-log files
     * under /var/lib/docker/containers, which are root-owned 0600 — so it runs as root (uid 0), the
     * standard posture for a log-tailing agent sidecar (Promtail, Fluent Bit, Grafana Alloy all do
     * the same). The sidecar takes no inbound traffic and mounts the log dir read-only.
     */
    private const COLLECTOR_UID = 10001;

    private function composeFragment(CollectorBufferPolicy $policy, bool $logs, LogPipelineConfig $logConfig): string
    {
        $storageDir = $policy->storageDir;
        $collectorUid = $logs ? 0 : self::COLLECTOR_UID;

        $collectorVolumes = [
            './observability/collector/otel-collector-config.yaml:/etc/otelcol/config.yaml:ro',
            'vortos-otelcol-storage:' . $storageDir,
        ];
        // hostmetrics reads the host's /proc, /sys, filesystems via root_path=/hostfs — bind the host
        // root read-only. (docker_stats needs no mount: it dials the socket-proxy over the network.)
        if ($policy->hostMetrics) {
            $collectorVolumes[] = '/:/hostfs:ro';
        }
        // Read-only bind of each container-log directory the filelog receiver tails.
        foreach ($this->logMounts($logs, $logConfig) as $mount) {
            $collectorVolumes[] = $mount;
        }

        $fragment = [
            'services' => [
                // One-shot: make the persistent-queue volume writable by the collector uid, then exit.
                // The collector waits for this to complete successfully before starting.
                'otel-collector-init' => [
                    'image' => 'busybox:1.36',
                    'user' => '0:0',
                    'command' => ['sh', '-c', sprintf('chown -R %d:%d %s', $collectorUid, $collectorUid, $storageDir)],
                    'volumes' => [
                        'vortos-otelcol-storage:' . $storageDir,
                    ],
                    'restart' => 'no',
                ],
                'otel-collector' => [
                    'image' => 'otel/opentelemetry-collector-contrib:0.103.0',
                    // Pin the runtime uid so the persistent-queue dir (chowned above) is always writable
                    // regardless of the image's default user; root when tailing root-owned container logs.
                    'user' => sprintf('%d:%d', $collectorUid, $collectorUid),
                    'restart' => 'unless-stopped',
                    'command' => ['--config=/etc/otelcol/config.yaml'],
                    'depends_on' => [
                        'otel-collector-init' => ['condition' => 'service_completed_successfully'],
                    ],
                    'volumes' => $collectorVolumes,
                    'ports' => ['127.0.0.1:4317:4317', '127.0.0.1:4318:4318'],
                ],
            ],
            'volumes' => [
                'vortos-otelcol-storage' => ['driver' => 'local'],
            ],
        ];

        return $this->yaml->dump($fragment);
    }

    /**
     * The read-only bind mounts the collector needs so the filelog receiver can see the host's
     * container-log files. Derived from the include globs: the static directory prefix of each
     * glob is mounted at the same path inside the collector (so the in-container include path
     * matches the host path). Read-only — the collector never writes application logs.
     *
     * @return list<string>
     */
    private function logMounts(bool $logs, LogPipelineConfig $logConfig): array
    {
        if (!$logs) {
            return [];
        }

        $dirs = [];
        foreach ($logConfig->includePaths as $glob) {
            $static = $glob;
            foreach (['*', '?', '['] as $meta) {
                $pos = strpos($static, $meta);
                if ($pos !== false) {
                    $static = substr($static, 0, $pos);
                }
            }
            $dir = str_ends_with($static, '/')
                ? rtrim($static, '/')
                : rtrim((string) dirname($static), '/');
            if ($dir === '' || $dir === '.') {
                continue;
            }
            $dirs[$dir] = sprintf('%s:%s:ro', $dir, $dir);
        }
        ksort($dirs);

        return array_values($dirs);
    }
}
