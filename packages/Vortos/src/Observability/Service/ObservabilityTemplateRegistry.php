<?php

declare(strict_types=1);

namespace Vortos\Observability\Service;

final class ObservabilityTemplateRegistry
{
    public function __construct(private readonly string $root) {}

    /**
     * @return array<string, ObservabilityStack>
     */
    public function stacks(): array
    {
        return [
            'prometheus' => new ObservabilityStack(
                'prometheus',
                'Prometheus recording and alert rules for Vortos metrics.',
                [
                    'prometheus/vortos-recording-rules.yml',
                    'prometheus/vortos-alert-rules.yml',
                ],
            ),
            'grafana' => new ObservabilityStack(
                'grafana',
                'Grafana dashboard starter for Vortos HTTP, CQRS, messaging, cache, persistence, and security metrics.',
                [
                    'grafana/vortos-overview-dashboard.json',
                ],
            ),
            'alertmanager' => new ObservabilityStack(
                'alertmanager',
                'Alertmanager routing example for Vortos alert labels.',
                [
                    'alertmanager/vortos-alertmanager.yml',
                ],
            ),
            'datadog' => new ObservabilityStack(
                'datadog',
                'Datadog dashboard and monitor examples for StatsD metrics, JSON logs, and OTLP traces.',
                [
                    'datadog/vortos-dashboard.json',
                    'datadog/vortos-monitors.json',
                    'datadog/README.md',
                ],
            ),
            'newrelic' => new ObservabilityStack(
                'newrelic',
                'New Relic dashboard and alert examples for OTLP traces, logs, and Prometheus/StatsD-style metrics.',
                [
                    'newrelic/vortos-dashboard.json',
                    'newrelic/vortos-alerts.yml',
                    'newrelic/README.md',
                ],
            ),
            'grafana-oss' => new ObservabilityStack(
                'grafana-oss',
                'Combined open-source stack: Prometheus + Grafana + Alertmanager.',
                [
                    'prometheus/vortos-recording-rules.yml',
                    'prometheus/vortos-alert-rules.yml',
                    'grafana/vortos-overview-dashboard.json',
                    'alertmanager/vortos-alertmanager.yml',
                ],
            ),
        ];
    }

    public function get(string $name): ?ObservabilityStack
    {
        return $this->stacks()[$name] ?? null;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->stacks());
    }

    public function sourcePath(string $relativePath): string
    {
        $path = realpath($this->root . DIRECTORY_SEPARATOR . $relativePath);
        if ($path === false || !str_starts_with($path, realpath($this->root) ?: $this->root)) {
            throw new \InvalidArgumentException(sprintf('Unknown observability template "%s".', $relativePath));
        }

        return $path;
    }
}

