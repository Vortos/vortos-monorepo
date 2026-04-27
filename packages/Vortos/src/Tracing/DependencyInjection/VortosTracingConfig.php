<?php
declare(strict_types=1);

namespace Vortos\Tracing\DependencyInjection;

use Vortos\Tracing\Config\TracingModule;
use Vortos\Tracing\Config\TracingSampler;

final class VortosTracingConfig
{
    private TracingSampler $sampler = TracingSampler::Ratio;
    private float $samplerRate = 0.1;
    private array $disabledModules = [];

    public function sampler(TracingSampler $sampler, float $rate = 0.1): static
    {
        $this->sampler = $sampler;
        $this->samplerRate = $rate;
        return $this;
    }

    public function disable(TracingModule ...$modules): static
    {
        foreach ($modules as $module) {
            $this->disabledModules[] = $module;
        }
        return $this;
    }

    public function enable(TracingModule ...$modules): static
    {
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

    public function getDisabledModules(): array
    {
        return $this->disabledModules;
    }
}
