<?php

declare(strict_types=1);

namespace Vortos\Observability\Tests\Unit\Driver;

use PHPUnit\Framework\TestCase;
use Vortos\Observability\Driver\Null\NullErrorSink;
use Vortos\Observability\Driver\Null\NullMetricsSink;
use Vortos\Observability\Sink\Capability\SinkCapability;
use Vortos\Observability\Sink\CapturedError;

final class NullSinksTest extends TestCase
{
    public function test_null_metrics_sink_carries_no_signals(): void
    {
        self::assertSame([], (new NullMetricsSink())->signals());
    }

    public function test_null_metrics_sink_is_not_off_host(): void
    {
        self::assertFalse((new NullMetricsSink())->capabilities()->supports(SinkCapability::OffHost));
    }

    public function test_null_error_sink_capture_and_flush_are_noops(): void
    {
        $sink = new NullErrorSink();
        $sink->capture(CapturedError::fromMessage('x'));
        $sink->flush();

        self::assertSame('null', $sink->name());
    }

    public function test_null_error_sink_is_not_off_host(): void
    {
        self::assertFalse((new NullErrorSink())->capabilities()->supports(SinkCapability::OffHost));
    }
}
