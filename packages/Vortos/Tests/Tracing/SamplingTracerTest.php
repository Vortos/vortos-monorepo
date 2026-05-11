<?php
declare(strict_types=1);

namespace Vortos\Tests\Tracing;

use PHPUnit\Framework\TestCase;
use Vortos\Tracing\Decorator\SamplingTracer;
use Vortos\Tracing\Decorator\SamplingSpan;
use Vortos\Tracing\NoOpSpan;
use Vortos\Tracing\NoOpTracer;
use Vortos\Tracing\Sampling\AlwaysOffSampler;
use Vortos\Tracing\Sampling\AlwaysOnSampler;

final class SamplingTracerTest extends TestCase
{
    public function test_returns_noop_span_when_sampler_says_no(): void
    {
        $tracer = new SamplingTracer(new NoOpTracer(), new AlwaysOffSampler());
        $span = $tracer->startSpan('test.span');
        $this->assertInstanceOf(SamplingSpan::class, $span);
        $span->end();
    }

    public function test_delegates_to_inner_when_sampler_says_yes(): void
    {
        $inner = $this->createMock(\Vortos\Tracing\Contract\TracingInterface::class);
        $inner->expects($this->once())
            ->method('startSpan')
            ->with('test.span', [])
            ->willReturn(new NoOpSpan());

        $tracer = new SamplingTracer($inner, new AlwaysOnSampler());
        $tracer->startSpan('test.span');
    }

    public function test_inject_headers_always_delegates(): void
    {
        $inner = $this->createMock(\Vortos\Tracing\Contract\TracingInterface::class);
        $inner->expects($this->once())->method('injectHeaders');

        $tracer = new SamplingTracer($inner, new AlwaysOffSampler());
        $headers = [];
        $tracer->injectHeaders($headers);
    }

    public function test_extract_context_always_delegates(): void
    {
        $inner = $this->createMock(\Vortos\Tracing\Contract\TracingInterface::class);
        $inner->expects($this->once())->method('extractContext');

        $tracer = new SamplingTracer($inner, new AlwaysOffSampler());
        $tracer->extractContext([]);
    }

    public function test_current_correlation_id_always_delegates(): void
    {
        $inner = $this->createMock(\Vortos\Tracing\Contract\TracingInterface::class);
        $inner->expects($this->once())
            ->method('currentCorrelationId')
            ->willReturn('abc123');

        $tracer = new SamplingTracer($inner, new AlwaysOffSampler());
        $this->assertSame('abc123', $tracer->currentCorrelationId());
    }
}
