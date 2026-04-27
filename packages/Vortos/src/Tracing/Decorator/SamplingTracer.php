<?php
declare(strict_types=1);

namespace Vortos\Tracing\Decorator;

use Vortos\Tracing\Contract\SpanInterface;
use Vortos\Tracing\Contract\TracingInterface;
use Vortos\Tracing\NoOpSpan;
use Vortos\Tracing\Sampling\SamplerInterface;

final class SamplingTracer implements TracingInterface
{
    public function __construct(
        private readonly TracingInterface $inner,
        private readonly SamplerInterface $sampler
    ) {}

    public function startSpan(string $name, array $attributes = []): SpanInterface
    {
        if (!$this->sampler->shouldSample()) {
            return new NoOpSpan();
        }

        return $this->inner->startSpan($name, $attributes);
    }

    public function injectHeaders(array &$headers): void
    {
        $this->inner->injectHeaders($headers);
    }

    public function extractContext(array $headers): void
    {
        $this->inner->extractContext($headers);
    }

    public function currentCorrelationId(): ?string
    {
        return $this->inner->currentCorrelationId();
    }
}
