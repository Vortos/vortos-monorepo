<?php

declare(strict_types=1);

namespace Vortos\Messaging\Attribute\Header;

use Attribute;

/**
 * Injects the trace ID from the envelope metadata into the marked handler parameter.
 *
 * The parameter type must be string (nullable — value may be absent).
 * Ties together all spans in a distributed trace (OpenTelemetry / Jaeger / Zipkin).
 *
 * Example:
 *   public function __invoke(OrderPlaced $event, #[TraceId] ?string $traceId): void {}
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class TraceId {}
