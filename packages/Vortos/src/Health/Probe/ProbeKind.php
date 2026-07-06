<?php

declare(strict_types=1);

namespace Vortos\Health\Probe;

enum ProbeKind: string
{
    case Liveness = 'liveness';
    case Readiness = 'readiness';
    case Startup = 'startup';

    /**
     * Off-gate observability (GAP-F): sampled by the monitor tick / surfaced at /health/monitor, but
     * NEVER aggregated into /health/live|ready|startup. A monitoring breach (e.g. a near-expiry TLS
     * cert the internal color cannot even reach) must never fail a K8s-style gate or roll back a deploy.
     */
    case Monitoring = 'monitoring';
}
