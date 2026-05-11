<?php
declare(strict_types=1);

namespace Vortos\Tests\Tracing;

use PHPUnit\Framework\TestCase;
use Vortos\Tracing\OpenTelemetry\OpenTelemetryTracerFactory;

final class OpenTelemetryTracerFactoryTest extends TestCase
{
    public function test_fails_fast_when_exporter_stack_is_missing(): void
    {
        if (class_exists('OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessorBuilder')
            && class_exists('OpenTelemetry\Contrib\Otlp\SpanExporter')
            && class_exists('OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory')
        ) {
            $this->markTestSkipped('OpenTelemetry exporter stack is installed in this environment.');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('OpenTelemetry adapter requires');

        OpenTelemetryTracerFactory::create([
            'service_name' => 'test',
            'service_version' => '',
            'deployment_environment' => 'test',
            'endpoint' => 'http://localhost:4318/v1/traces',
            'protocol' => 'http/protobuf',
            'headers' => [],
            'timeout_ms' => 2000,
        ]);
    }
}
