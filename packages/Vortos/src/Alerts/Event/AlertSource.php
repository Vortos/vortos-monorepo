<?php

declare(strict_types=1);

namespace Vortos\Alerts\Event;

/** Where an {@see AlertEvent} originated — never a backend name (that lives in Driver/). */
enum AlertSource: string
{
    case Slo = 'slo';
    case Health = 'health';
    case Deploy = 'deploy';
    case Backup = 'backup';
    case Capacity = 'capacity';
    case Cert = 'cert';
    case Queue = 'queue';
    case Synthetic = 'synthetic';
    case SupplyChain = 'supply-chain';
}
