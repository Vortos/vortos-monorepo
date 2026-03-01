<?php

declare(strict_types=1);

namespace Fortizan\Tekton\Tracing;

use Fortizan\Tekton\Tracing\Contract\SpanInterface;
use Throwable;

/**
 * No-operation span. Returned by NoOpTracer.
 * All methods do nothing. Zero overhead beyond object construction.
 * Safe in all environments including high-throughput production when tracing is disabled.
 */
final class NoOpSpan implements SpanInterface
{
    public function end(): void {}

    public function addAttribute(string $key, mixed $value): void {}

    public function recordException(Throwable $e): void {}

    public function setStatus(string $status): void {}
}
