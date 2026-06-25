<?php

declare(strict_types=1);

namespace Vortos\Observability\Collector;

/**
 * A rendered OpenTelemetry Collector configuration: the canonical PHP array (the
 * authoritative, deterministic form) plus YAML rendering for the operator-mounted
 * sidecar file.
 *
 * The array is the source of truth — contract tests assert against it; the YAML is a
 * faithful, byte-stable projection of it.
 */
final readonly class CollectorConfig
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(public array $config) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->config;
    }

    public function toYaml(?YamlWriter $writer = null): string
    {
        return ($writer ?? new YamlWriter())->dump($this->config);
    }
}
