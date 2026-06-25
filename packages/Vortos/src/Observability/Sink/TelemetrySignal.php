<?php

declare(strict_types=1);

namespace Vortos\Observability\Sink;

/**
 * The three OpenTelemetry signal families a sink/exporter can carry.
 *
 * A sink declares which signals it carries via {@see MetricsSinkInterface::signals()};
 * the collector config builder maps each carried signal to a service pipeline.
 */
enum TelemetrySignal: string
{
    case Metrics = 'metrics';
    case Traces = 'traces';
    case Logs = 'logs';
}
