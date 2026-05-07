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
    private bool $trustRemoteContext = false;

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
}
