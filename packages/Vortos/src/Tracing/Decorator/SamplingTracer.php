<?php
declare(strict_types=1);

namespace Vortos\Tracing\Decorator;

use Vortos\Tracing\Contract\SpanInterface;
use Vortos\Tracing\Contract\TracingInterface;
use Vortos\Tracing\NoOpSpan;
use Vortos\Tracing\Sampling\SamplerInterface;

final class SamplingTracer implements TracingInterface
{
    /** @var list<bool> */
    private array $sampledStack = [];

    public function __construct(
        private readonly TracingInterface $inner,
        private readonly SamplerInterface $sampler
    ) {}

    public function startSpan(string $name, array $attributes = []): SpanInterface
    {
        $sample = $this->sampledStack === []
            ? ($this->inner->currentCorrelationId() !== null || $this->shouldSampleRoot($attributes))
            : $this->sampledStack[array_key_last($this->sampledStack)];

        $this->sampledStack[] = $sample;

        if (!$sample) {
            return new SamplingSpan(new NoOpSpan(), $this->popSample(...));
        }

        return new SamplingSpan($this->inner->startSpan($name, $attributes), $this->popSample(...));
    }

    public function injectHeaders(array &$headers): void
    {
        $this->inner->injectHeaders($headers);
    }

    public function extractContext(array $headers): void
    {
        $this->inner->extractContext($headers);
    }

    public function setBaggageItem(string $key, string $value): void
    {
        $this->inner->setBaggageItem($key, $value);
    }

    public function baggageItem(string $key): ?string
    {
        return $this->inner->baggageItem($key);
    }

    public function baggage(): array
    {
        return $this->inner->baggage();
    }

    public function currentCorrelationId(): ?string
    {
        return $this->inner->currentCorrelationId();
    }

    private function popSample(): void
    {
        array_pop($this->sampledStack);
    }

    private function shouldSampleRoot(array $attributes): bool
    {
        $rate = $attributes['vortos.trace.sample_rate'] ?? null;
        if (is_float($rate) || is_int($rate)) {
            $rate = max(0.0, min(1.0, (float) $rate));
            return (mt_rand() / mt_getrandmax()) <= $rate;
        }

        return $this->sampler->shouldSample();
    }
}
