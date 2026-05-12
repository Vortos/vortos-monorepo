<?php
declare(strict_types=1);

namespace Vortos\Tests\Tracing;

use PHPUnit\Framework\TestCase;
use Vortos\Observability\Config\ObservabilityModule;
use Vortos\Tracing\Config\TracingAdapter;
use Vortos\Tracing\Config\TracingModule;
use Vortos\Tracing\Config\TracingSampler;
use Vortos\Tracing\DependencyInjection\VortosTracingConfig;

final class VortosTracingConfigTest extends TestCase
{
    public function test_default_sampler_is_ratio(): void
    {
        $config = new VortosTracingConfig();
        $this->assertSame(TracingSampler::Ratio, $config->getSampler());
        $this->assertSame(0.1, $config->getSamplerRate());
        $this->assertSame(TracingAdapter::NoOp, $config->getAdapter());
    }

    public function test_default_no_disabled_modules(): void
    {
        $config = new VortosTracingConfig();
        $this->assertEmpty($config->getDisabledModules());
    }

    public function test_can_set_always_on_sampler(): void
    {
        $config = new VortosTracingConfig();
        $config->sampler(TracingSampler::AlwaysOn);
        $this->assertSame(TracingSampler::AlwaysOn, $config->getSampler());
    }

    public function test_can_set_always_off_sampler(): void
    {
        $config = new VortosTracingConfig();
        $config->sampler(TracingSampler::AlwaysOff);
        $this->assertSame(TracingSampler::AlwaysOff, $config->getSampler());
    }

    public function test_can_set_ratio_sampler_with_custom_rate(): void
    {
        $config = new VortosTracingConfig();
        $config->sampler(TracingSampler::Ratio, rate: 0.25);
        $this->assertSame(TracingSampler::Ratio, $config->getSampler());
        $this->assertSame(0.25, $config->getSamplerRate());
    }

    public function test_sampler_rate_is_clamped(): void
    {
        $config = new VortosTracingConfig();
        $config->sampler(TracingSampler::Ratio, rate: 99);
        $this->assertSame(1.0, $config->getSamplerRate());

        $config->sampler(TracingSampler::Ratio, rate: -1);
        $this->assertSame(0.0, $config->getSamplerRate());
    }

    public function test_can_configure_opentelemetry_adapter_and_otlp(): void
    {
        $config = (new VortosTracingConfig())
            ->adapter(TracingAdapter::OpenTelemetry)
            ->service('checkout', '1.0.0', 'prod')
            ->otlp('https://otel.example.test/v1/traces', headers: ['x-api-key' => 'secret'], timeoutMs: 500);

        $otel = $config->getOpenTelemetryConfig();

        $this->assertSame(TracingAdapter::OpenTelemetry, $config->getAdapter());
        $this->assertSame('checkout', $otel['service_name']);
        $this->assertSame('1.0.0', $otel['service_version']);
        $this->assertSame('prod', $otel['deployment_environment']);
        $this->assertSame('https://otel.example.test/v1/traces', $otel['endpoint']);
        $this->assertSame(['x-api-key' => 'secret'], $otel['headers']);
        $this->assertSame(500, $otel['timeout_ms']);
    }

    public function test_otlp_endpoint_must_be_absolute_http_url(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new VortosTracingConfig())->otlp('file:///tmp/traces');
    }

    public function test_can_disable_single_module(): void
    {
        $config = new VortosTracingConfig();
        $config->disable(TracingModule::Cache);
        $this->assertContains(ObservabilityModule::Cache, $config->getDisabledModules());
    }

    public function test_can_disable_multiple_modules(): void
    {
        $config = new VortosTracingConfig();
        $config->disable(TracingModule::Cache, TracingModule::Auth);
        $this->assertContains(ObservabilityModule::Cache, $config->getDisabledModules());
        $this->assertContains(ObservabilityModule::Auth, $config->getDisabledModules());
    }

    public function test_can_re_enable_disabled_module(): void
    {
        $config = new VortosTracingConfig();
        $config->disable(TracingModule::Cache, TracingModule::Auth);
        $config->enable(TracingModule::Cache);
        $this->assertNotContains(ObservabilityModule::Cache, $config->getDisabledModules());
        $this->assertContains(ObservabilityModule::Auth, $config->getDisabledModules());
    }

    public function test_fluent_interface_returns_same_instance(): void
    {
        $config = new VortosTracingConfig();
        $this->assertSame($config, $config->sampler(TracingSampler::AlwaysOn));
        $this->assertSame($config, $config->adapter(TracingAdapter::NoOp));
        $this->assertSame($config, $config->disable(TracingModule::Cache));
        $this->assertSame($config, $config->enable(TracingModule::Cache));
    }
}
