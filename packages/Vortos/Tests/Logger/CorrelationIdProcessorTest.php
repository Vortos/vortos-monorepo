<?php

declare(strict_types=1);

namespace Vortos\Tests\Logger;

use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Vortos\Logger\Processor\CorrelationIdProcessor;
use Vortos\Tracing\Contract\TracingInterface;
use Vortos\Tracing\NoOpTracer;

final class CorrelationIdProcessorTest extends TestCase
{
    public function test_adds_trace_id_when_tracer_returns_id(): void
    {
        $tracer = $this->createMock(TracingInterface::class);
        $tracer->method('currentCorrelationId')->willReturn('abc123');

        $processor = new CorrelationIdProcessor($tracer);
        $record    = $this->makeRecord();
        $result    = $processor($record);

        $this->assertSame('abc123', $result->extra['trace_id']);
    }

    public function test_no_op_when_tracer_returns_null(): void
    {
        $processor = new CorrelationIdProcessor(new NoOpTracer());
        $record    = $this->makeRecord();
        $result    = $processor($record);

        $this->assertArrayNotHasKey('trace_id', $result->extra);
        $this->assertSame($record, $result);
    }

    public function test_preserves_existing_extra_fields(): void
    {
        $tracer = $this->createMock(TracingInterface::class);
        $tracer->method('currentCorrelationId')->willReturn('xyz');

        $processor = new CorrelationIdProcessor($tracer);
        $record    = $this->makeRecord(['request_id' => 'req-1']);
        $result    = $processor($record);

        $this->assertSame('xyz', $result->extra['trace_id']);
        $this->assertSame('req-1', $result->extra['request_id']);
    }

    private function makeRecord(array $extra = []): LogRecord
    {
        return new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'test',
            level: Level::Info,
            message: 'test message',
            context: [],
            extra: $extra,
        );
    }
}
