<?php

declare(strict_types=1);

use Vortos\Tracing\Config\TracingModule;
use Vortos\Tracing\Config\TracingSampler;
use Vortos\Tracing\DependencyInjection\VortosTracingConfig;

return static function (VortosTracingConfig $config): void {
    // Defaults: AlwaysOn in dev, Ratio(0.1) in prod — override here if needed.

    // Sample all traces in development:
    // $config->sampler(TracingSampler::AlwaysOn);

    // Sample 10% in production (default):
    // $config->sampler(TracingSampler::Ratio, rate: 0.1);

    // Silence high-volume Cache and Persistence spans:
    // $config->disable(TracingModule::Cache, TracingModule::Persistence);
};
