<?php

declare(strict_types=1);

namespace Vortos\Observability\Sink\Capability;

use Vortos\OpsKit\Driver\Capability\CapabilityKey;

/**
 * The capabilities a telemetry sink driver may declare — the single source of truth
 * validated at config time (never at 3am).
 *
 * `off_host` is the §12.4 invariant: the observability plane must live off the app
 * host (an on-host backend cannot report that the host is dead and competes for its
 * RAM). A sink that declares `off_host=false` is refusable for prod at config-
 * validation time.
 */
enum SinkCapability: string implements CapabilityKey
{
    case Metrics = 'metrics';
    case Traces = 'traces';
    case Logs = 'logs';
    case OffHost = 'off_host';
    case DiskBuffering = 'disk_buffering';
    case OtlpNative = 'otlp_native';
    case Tls = 'tls';

    public function key(): string
    {
        return $this->value;
    }
}
