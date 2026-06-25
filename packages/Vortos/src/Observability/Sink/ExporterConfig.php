<?php

declare(strict_types=1);

namespace Vortos\Observability\Sink;

use InvalidArgumentException;

/**
 * The collector **exporter fragment** for one backend — the single place a backend's
 * shape is expressed, and the swap point the whole §12.4 design hinges on.
 *
 * A {@see MetricsSinkInterface} driver renders one of these; the
 * {@see \Vortos\Observability\Collector\CollectorConfigBuilder} embeds it under the
 * collector's `exporters:` key. Switching backends changes only which driver renders
 * this fragment — never app code.
 *
 * Canonically serializable: {@see toArray()} sorts keys so the rendered collector
 * config is deterministic and a contract test can pin it byte-for-byte.
 */
final readonly class ExporterConfig
{
    /**
     * @param non-empty-string                                          $type     Collector exporter type, e.g. `otlp`, `otlphttp`
     * @param array<string, scalar|array<string, scalar|null>|null>     $settings Exporter settings (endpoint, tls, headers, retry, sending_queue, …)
     */
    private function __construct(
        public string $type,
        public array $settings,
    ) {}

    /**
     * @param array<string, mixed> $settings
     */
    public static function create(string $type, array $settings): self
    {
        if ($type === '') {
            throw new InvalidArgumentException('Exporter type must be a non-empty string.');
        }

        self::assertSettings($settings);

        /** @var array<string, scalar|array<string, scalar|null>|null> $settings */
        return new self($type, $settings);
    }

    /**
     * @param array<array-key, mixed> $settings
     */
    private static function assertSettings(array $settings): void
    {
        foreach ($settings as $key => $value) {
            if (!is_string($key) || $key === '') {
                throw new InvalidArgumentException('Exporter setting names must be non-empty strings.');
            }
            if ($value === null || is_scalar($value)) {
                continue;
            }
            if (is_array($value)) {
                foreach ($value as $subKey => $subValue) {
                    if (!is_string($subKey) || $subKey === '') {
                        throw new InvalidArgumentException("Exporter setting '{$key}' nested names must be non-empty strings.");
                    }
                    if ($subValue !== null && !is_scalar($subValue)) {
                        throw new InvalidArgumentException("Exporter setting '{$key}.{$subKey}' must be scalar or null.");
                    }
                }
                continue;
            }

            throw new InvalidArgumentException(
                "Exporter setting '{$key}' must be scalar, null, or a one-level scalar map, got " . get_debug_type($value) . '.'
            );
        }
    }

    /**
     * Canonical, deterministic serialization (keys sorted, recursively).
     *
     * @return array{type:string, settings:array<string, scalar|array<string, scalar|null>|null>}
     */
    public function toArray(): array
    {
        $settings = $this->settings;
        ksort($settings);
        foreach ($settings as $key => $value) {
            if (is_array($value)) {
                ksort($value);
                $settings[$key] = $value;
            }
        }

        return [
            'type' => $this->type,
            'settings' => $settings,
        ];
    }
}
