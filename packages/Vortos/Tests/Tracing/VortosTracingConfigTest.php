<?php
declare(strict_types=1);

namespace Vortos\Tests\Tracing;

use PHPUnit\Framework\TestCase;
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

    public function test_can_disable_single_module(): void
    {
        $config = new VortosTracingConfig();
        $config->disable(TracingModule::Cache);
        $this->assertContains(TracingModule::Cache, $config->getDisabledModules());
    }

    public function test_can_disable_multiple_modules(): void
    {
        $config = new VortosTracingConfig();
        $config->disable(TracingModule::Cache, TracingModule::Auth);
        $this->assertContains(TracingModule::Cache, $config->getDisabledModules());
        $this->assertContains(TracingModule::Auth, $config->getDisabledModules());
    }

    public function test_can_re_enable_disabled_module(): void
    {
        $config = new VortosTracingConfig();
        $config->disable(TracingModule::Cache, TracingModule::Auth);
        $config->enable(TracingModule::Cache);
        $this->assertNotContains(TracingModule::Cache, $config->getDisabledModules());
        $this->assertContains(TracingModule::Auth, $config->getDisabledModules());
    }

    public function test_fluent_interface_returns_same_instance(): void
    {
        $config = new VortosTracingConfig();
        $this->assertSame($config, $config->sampler(TracingSampler::AlwaysOn));
        $this->assertSame($config, $config->disable(TracingModule::Cache));
        $this->assertSame($config, $config->enable(TracingModule::Cache));
    }
}
