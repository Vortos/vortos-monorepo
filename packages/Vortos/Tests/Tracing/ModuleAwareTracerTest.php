<?php
declare(strict_types=1);

namespace Vortos\Tests\Tracing;

use PHPUnit\Framework\TestCase;
use Vortos\Tracing\Config\TracingModule;
use Vortos\Tracing\Contract\TracingInterface;
use Vortos\Tracing\Decorator\ModuleAwareTracer;
use Vortos\Tracing\NoOpSpan;
use Vortos\Tracing\NoOpTracer;

final class ModuleAwareTracerTest extends TestCase
{
    public function test_returns_noop_span_when_module_is_disabled(): void
    {
        $tracer = new ModuleAwareTracer(
            new NoOpTracer(),
            [TracingModule::Cache]
        );

        $span = $tracer->startSpan('cache.get', [
            'vortos.module' => TracingModule::Cache,
        ]);

        $this->assertInstanceOf(NoOpSpan::class, $span);
    }

    public function test_delegates_when_module_is_enabled(): void
    {
        $inner = $this->createMock(TracingInterface::class);
        $inner->expects($this->once())
            ->method('startSpan')
            ->willReturn(new NoOpSpan());

        $tracer = new ModuleAwareTracer($inner, []);
        $tracer->startSpan('cache.get', ['vortos.module' => TracingModule::Cache]);
    }

    public function test_delegates_when_no_module_attribute(): void
    {
        $inner = $this->createMock(TracingInterface::class);
        $inner->expects($this->once())
            ->method('startSpan')
            ->willReturn(new NoOpSpan());

        $tracer = new ModuleAwareTracer($inner, [TracingModule::Cache]);
        $tracer->startSpan('some.span');
    }

    public function test_multiple_disabled_modules(): void
    {
        $tracer = new ModuleAwareTracer(
            new NoOpTracer(),
            [TracingModule::Cache, TracingModule::Auth]
        );

        $cacheSpan = $tracer->startSpan('cache.get', ['vortos.module' => TracingModule::Cache]);
        $authSpan = $tracer->startSpan('auth.login', ['vortos.module' => TracingModule::Auth]);
        $httpSpan = $tracer->startSpan('http.request', ['vortos.module' => TracingModule::Http]);

        $this->assertInstanceOf(NoOpSpan::class, $cacheSpan);
        $this->assertInstanceOf(NoOpSpan::class, $authSpan);
        // Http is not disabled — but inner is NoOpTracer so still NoOpSpan
        $this->assertInstanceOf(NoOpSpan::class, $httpSpan);
    }

    public function test_inject_headers_always_delegates(): void
    {
        $inner = $this->createMock(TracingInterface::class);
        $inner->expects($this->once())->method('injectHeaders');

        $tracer = new ModuleAwareTracer($inner, []);
        $headers = [];
        $tracer->injectHeaders($headers);
    }

    public function test_extract_context_always_delegates(): void
    {
        $inner = $this->createMock(TracingInterface::class);
        $inner->expects($this->once())->method('extractContext');

        $tracer = new ModuleAwareTracer($inner, []);
        $tracer->extractContext([]);
    }

    public function test_current_correlation_id_delegates(): void
    {
        $inner = $this->createMock(TracingInterface::class);
        $inner->expects($this->once())
            ->method('currentCorrelationId')
            ->willReturn('trace-xyz');

        $tracer = new ModuleAwareTracer($inner, []);
        $this->assertSame('trace-xyz', $tracer->currentCorrelationId());
    }
}
