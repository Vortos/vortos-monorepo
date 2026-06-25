<?php

declare(strict_types=1);

namespace Vortos\Observability\Marker;

use Vortos\OpsKit\Driver\DriverInterface;

/**
 * The collector-exporter-name swap point for deploy markers (Block 16, §3.2) —
 * mirrors {@see \Vortos\Observability\Sink\MetricsSinkInterface}: the only place a
 * backend name appears outside `Driver/`.
 *
 * `emit()` must be best-effort and **never throw** into the caller — enforced by
 * {@see \Vortos\Observability\Testing\MarkerEmitterConformanceTestCase}, the same
 * contract discipline as {@see \Vortos\Observability\Sink\ErrorSinkInterface}.
 */
interface MarkerEmitterInterface extends DriverInterface
{
    public function name(): string;

    public function emit(DeployMarker $marker): void;
}
