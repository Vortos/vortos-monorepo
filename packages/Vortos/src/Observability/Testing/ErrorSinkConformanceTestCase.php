<?php

declare(strict_types=1);

namespace Vortos\Observability\Testing;

use RuntimeException;
use Vortos\Observability\Sink\CapturedError;
use Vortos\Observability\Sink\ErrorSinkInterface;
use Vortos\OpsKit\Testing\ConformanceTestCase;

/**
 * The error-sink TCK (§10.4). The defining contract: {@see ErrorSinkInterface::capture()}
 * and {@see ErrorSinkInterface::flush()} are best-effort and MUST NOT throw into the
 * caller — even when the underlying transport explodes. This base proves that with a
 * throwing collaborator where the driver allows one to be injected.
 */
abstract class ErrorSinkConformanceTestCase extends ConformanceTestCase
{
    abstract protected function createSink(): ErrorSinkInterface;

    protected function createDriver(): ErrorSinkInterface
    {
        return $this->createSink();
    }

    final public function test_name_matches_registered_key(): void
    {
        self::assertSame($this->expectedKey(), $this->createSink()->name());
    }

    final public function test_capture_never_throws(): void
    {
        $sink = $this->createSink();
        $error = CapturedError::fromThrowable(new RuntimeException('boom'));

        $sink->capture($error);
        $this->addToAssertionCount(1); // reaching here = it did not throw
    }

    final public function test_flush_never_throws(): void
    {
        $sink = $this->createSink();
        $sink->flush();
        $this->addToAssertionCount(1);
    }

    final public function test_capture_then_flush_never_throws(): void
    {
        $sink = $this->createSink();
        $sink->capture(CapturedError::fromMessage('something failed'));
        $sink->flush();
        $this->addToAssertionCount(1);
    }
}
