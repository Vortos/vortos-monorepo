<?php

declare(strict_types=1);

namespace Vortos\Paddle\DependencyInjection;

use Vortos\Paddle\Config\PaddleObservabilitySection;

final class PaddleObservabilityConfig
{
    private bool  $logging = true;
    private bool  $tracing = true;
    private bool  $metrics = true;
    private array $loggingDisabledFor = [];
    private array $tracingDisabledFor = [];
    private array $metricsDisabledFor = [];

    public function logging(bool $enabled): static
    {
        $this->logging = $enabled;
        return $this;
    }

    public function tracing(bool $enabled): static
    {
        $this->tracing = $enabled;
        return $this;
    }

    public function metrics(bool $enabled): static
    {
        $this->metrics = $enabled;
        return $this;
    }

    public function disableLoggingFor(PaddleObservabilitySection $section): static
    {
        $this->loggingDisabledFor[] = $section->value;
        return $this;
    }

    public function disableTracingFor(PaddleObservabilitySection $section): static
    {
        $this->tracingDisabledFor[] = $section->value;
        return $this;
    }

    public function disableMetricsFor(PaddleObservabilitySection $section): static
    {
        $this->metricsDisabledFor[] = $section->value;
        return $this;
    }

    public function toArray(): array
    {
        return [
            'logging'              => $this->logging,
            'tracing'              => $this->tracing,
            'metrics'              => $this->metrics,
            'logging_disabled_for' => $this->loggingDisabledFor,
            'tracing_disabled_for' => $this->tracingDisabledFor,
            'metrics_disabled_for' => $this->metricsDisabledFor,
        ];
    }
}
