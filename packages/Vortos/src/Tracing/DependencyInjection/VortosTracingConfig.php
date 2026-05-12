<?php
declare(strict_types=1);

namespace Vortos\Tracing\DependencyInjection;

use Vortos\Tracing\Config\TracingModule;
use Vortos\Tracing\Config\TracingAdapter;
use Vortos\Tracing\Config\TracingSampler;
use Vortos\Observability\Config\ObservabilityModule;

final class VortosTracingConfig
{
    private TracingAdapter $adapter = TracingAdapter::NoOp;
    private TracingSampler $sampler = TracingSampler::Ratio;
    private float $samplerRate = 0.1;
    private array $disabledModules = [];
    private bool $trustRemoteContext = false;
    private string $serviceName = 'app';
    private string $serviceVersion = '';
    private string $deploymentEnvironment = 'prod';
    private string $otlpEndpoint = 'http://localhost:4318/v1/traces';
    private string $otlpProtocol = 'http/protobuf';
    private int $otlpTimeoutMs = 2000;
    /** @var array<string, string> */
    private array $otlpHeaders = [];

    public function __construct()
    {
        $this->serviceName = $_ENV['OTEL_SERVICE_NAME'] ?? $_ENV['APP_NAME'] ?? 'app';
        $this->serviceVersion = $_ENV['APP_VERSION'] ?? '';
        $this->deploymentEnvironment = $_ENV['APP_ENV'] ?? 'prod';
        $this->otlpEndpoint = $_ENV['OTEL_EXPORTER_OTLP_TRACES_ENDPOINT']
            ?? $_ENV['OTEL_EXPORTER_OTLP_ENDPOINT']
            ?? $this->otlpEndpoint;
    }

    public function adapter(TracingAdapter $adapter): static
    {
        $this->adapter = $adapter;
        return $this;
    }

    public function sampler(TracingSampler $sampler, float $rate = 0.1): static
    {
        $this->sampler = $sampler;
        $this->samplerRate = max(0.0, min(1.0, $rate));
        return $this;
    }

    public function service(string $name, string $version = '', string $environment = ''): static
    {
        $this->serviceName = $name;
        $this->serviceVersion = $version;
        $this->deploymentEnvironment = $environment;
        return $this;
    }

    /**
     * @param array<string, string> $headers
     */
    public function otlp(string $endpoint, string $protocol = 'http/protobuf', array $headers = [], int $timeoutMs = 2000): static
    {
        if (!str_starts_with($endpoint, 'http://') && !str_starts_with($endpoint, 'https://')) {
            throw new \InvalidArgumentException('OTLP endpoint must be an absolute http(s) URL.');
        }

        $this->otlpEndpoint = $endpoint;
        $this->otlpProtocol = $protocol;
        $this->otlpHeaders = $headers;
        $this->otlpTimeoutMs = max(100, min(10000, $timeoutMs));
        return $this;
    }

    public function getAdapter(): TracingAdapter
    {
        return $this->adapter;
    }

    public function disable(TracingModule|ObservabilityModule ...$modules): static
    {
        foreach ($modules as $module) {
            $this->disabledModules[] = $module instanceof TracingModule
                ? $module->observabilityModule()
                : $module;
        }
        return $this;
    }

    public function enable(TracingModule|ObservabilityModule ...$modules): static
    {
        $modules = array_map(
            static fn (TracingModule|ObservabilityModule $module): ObservabilityModule => $module instanceof TracingModule
                ? $module->observabilityModule()
                : $module,
            $modules,
        );

        $this->disabledModules = array_filter(
            $this->disabledModules,
            fn($m) => !in_array($m, $modules, true)
        );
        return $this;
    }

    public function getSampler(): TracingSampler
    {
        return $this->sampler;
    }

    public function getSamplerRate(): float
    {
        return $this->samplerRate;
    }

    /**
     * Allow incoming W3C traceparent headers to set the parent span.
     *
     * Only enable when ALL requests come from internal services you control.
     * External callers with a crafted traceparent can inject trace IDs into
     * your backend. Default: false (safe for internet-facing services).
     */
    public function trustRemoteContext(bool $trust = true): static
    {
        $this->trustRemoteContext = $trust;
        return $this;
    }

    public function getDisabledModules(): array
    {
        return $this->disabledModules;
    }

    public function getTrustRemoteContext(): bool
    {
        return $this->trustRemoteContext;
    }

    /** @return array{service_name: string, service_version: string, deployment_environment: string, endpoint: string, protocol: string, headers: array<string, string>, timeout_ms: int} */
    public function getOpenTelemetryConfig(): array
    {
        return [
            'service_name' => $this->serviceName,
            'service_version' => $this->serviceVersion,
            'deployment_environment' => $this->deploymentEnvironment,
            'endpoint' => $this->otlpEndpoint,
            'protocol' => $this->otlpProtocol,
            'headers' => $this->otlpHeaders,
            'timeout_ms' => $this->otlpTimeoutMs,
        ];
    }
}
