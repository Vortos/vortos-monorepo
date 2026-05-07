<?php

declare(strict_types=1);

namespace Vortos\Tests\Metrics;

use PHPUnit\Framework\TestCase;
use Vortos\Metrics\Config\MetricsAdapter;
use Vortos\Metrics\Config\MetricsModule;
use Vortos\Metrics\DependencyInjection\VortosMetricsConfig;

final class VortosMetricsConfigTest extends TestCase
{
    public function test_defaults_to_noop_adapter(): void
    {
        $config = new VortosMetricsConfig();
        $array  = $config->toArray();

        $this->assertSame(MetricsAdapter::NoOp, $array['adapter']);
    }

    public function test_default_namespace_is_vortos(): void
    {
        $array = (new VortosMetricsConfig())->toArray();
        $this->assertSame('vortos', $array['namespace']);
    }

    public function test_default_disabled_modules_is_empty(): void
    {
        $array = (new VortosMetricsConfig())->toArray();
        $this->assertSame([], $array['disabled_modules']);
    }

    public function test_adapter_sets_adapter(): void
    {
        $config = (new VortosMetricsConfig())->adapter(MetricsAdapter::Prometheus);
        $this->assertSame(MetricsAdapter::Prometheus, $config->toArray()['adapter']);
    }

    public function test_namespace_sets_namespace(): void
    {
        $config = (new VortosMetricsConfig())->namespace('myapp');
        $this->assertSame('myapp', $config->toArray()['namespace']);
    }

    public function test_disable_module_adds_to_list(): void
    {
        $config = (new VortosMetricsConfig())->disableModule(MetricsModule::Cache, MetricsModule::Persistence);
        $disabled = $config->toArray()['disabled_modules'];
        $this->assertContains(MetricsModule::Cache, $disabled);
        $this->assertContains(MetricsModule::Persistence, $disabled);
    }

    public function test_prometheus_storage_defaults_to_memory(): void
    {
        $array = (new VortosMetricsConfig())->toArray();
        $this->assertSame('memory', $array['prometheus_storage']);
    }

    public function test_prometheus_storage_redis_sets_storage_and_prefix(): void
    {
        $config = (new VortosMetricsConfig())->prometheusStorageRedis(prefix: 'prom:', host: '10.0.0.1', port: 6380);
        $array  = $config->toArray();
        $this->assertSame('redis', $array['prometheus_storage']);
        $this->assertSame('prom:', $array['prometheus_redis_prefix']);
        $this->assertSame('10.0.0.1', $array['prometheus_redis_host']);
        $this->assertSame(6380, $array['prometheus_redis_port']);
    }

    public function test_prometheus_storage_apc_sets_storage(): void
    {
        $config = (new VortosMetricsConfig())->prometheusStorageApc();
        $this->assertSame('apc', $config->toArray()['prometheus_storage']);
    }

    public function test_prometheus_endpoint_token_sets_token(): void
    {
        $config = (new VortosMetricsConfig())->prometheusEndpointToken('secret123');
        $this->assertSame('secret123', $config->toArray()['prometheus_endpoint_token']);
    }

    public function test_statsd_host_port_sample_rate(): void
    {
        $config = (new VortosMetricsConfig())
            ->statsDHost('10.0.0.1')
            ->statsDPort(9125)
            ->statsDSampleRate(0.5);
        $array = $config->toArray();
        $this->assertSame('10.0.0.1', $array['statsd_host']);
        $this->assertSame(9125, $array['statsd_port']);
        $this->assertSame(0.5, $array['statsd_sample_rate']);
    }

    public function test_statsd_sample_rate_is_clamped_to_zero_one(): void
    {
        $config = (new VortosMetricsConfig())->statsDSampleRate(2.5);
        $this->assertSame(1.0, $config->toArray()['statsd_sample_rate']);

        $config = (new VortosMetricsConfig())->statsDSampleRate(-0.5);
        $this->assertSame(0.0, $config->toArray()['statsd_sample_rate']);
    }

    public function test_fluent_interface_returns_same_instance(): void
    {
        $config = new VortosMetricsConfig();
        $this->assertSame($config, $config->adapter(MetricsAdapter::StatsD));
        $this->assertSame($config, $config->namespace('x'));
        $this->assertSame($config, $config->statsDHost('host'));
    }
}
