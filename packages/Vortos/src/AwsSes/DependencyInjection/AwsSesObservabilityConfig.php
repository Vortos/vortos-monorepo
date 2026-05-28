<?php

declare(strict_types=1);

namespace Vortos\AwsSes\DependencyInjection;

use Vortos\AwsSes\Config\AwsSesObservabilitySection;

final class AwsSesObservabilityConfig
{
    private bool $logging = true;
    private bool $tracing = true;
    private bool $metrics = true;
    /** @var string[] */
    private array $loggingDisabledFor = [];
    /** @var string[] */
    private array $tracingDisabledFor = [];
    /** @var string[] */
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

    public function disableLoggingFor(AwsSesObservabilitySection ...$sections): static
    {
        $this->loggingDisabledFor = $this->mergeSections($this->loggingDisabledFor, $sections);
        return $this;
    }

    public function disableTracingFor(AwsSesObservabilitySection ...$sections): static
    {
        $this->tracingDisabledFor = $this->mergeSections($this->tracingDisabledFor, $sections);
        return $this;
    }

    public function disableMetricsFor(AwsSesObservabilitySection ...$sections): static
    {
        $this->metricsDisabledFor = $this->mergeSections($this->metricsDisabledFor, $sections);
        return $this;
    }

    /** @internal */
    public function toArray(): array
    {
        return [
            'logging' => $this->logging,
            'tracing' => $this->tracing,
            'metrics' => $this->metrics,
            'logging_disabled_for' => $this->loggingDisabledFor,
            'tracing_disabled_for' => $this->tracingDisabledFor,
            'metrics_disabled_for' => $this->metricsDisabledFor,
        ];
    }

    /** @param AwsSesObservabilitySection[] $sections */
    private function mergeSections(array $current, array $sections): array
    {
        return array_values(array_unique(array_merge(
            $current,
            array_map(static fn(AwsSesObservabilitySection $section): string => $section->value, $sections),
        )));
    }
}
