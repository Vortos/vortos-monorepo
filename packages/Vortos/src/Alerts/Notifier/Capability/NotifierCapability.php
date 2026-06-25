<?php

declare(strict_types=1);

namespace Vortos\Alerts\Notifier\Capability;

use Vortos\OpsKit\Driver\Capability\CapabilityKey;

/**
 * What a notifier driver declares it can do — the single source `deploy:doctor`
 * diffs against a route's requirement (e.g. a `critical` route to a driver without
 * `supports_paging` fails preflight, never silently never-pages).
 */
enum NotifierCapability: string implements CapabilityKey
{
    case SupportsPaging = 'supports_paging';
    case SupportsAck = 'supports_ack';
    case RichFormatting = 'rich_formatting';
    case OffHost = 'off_host';

    public function key(): string
    {
        return $this->value;
    }
}
