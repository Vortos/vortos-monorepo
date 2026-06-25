<?php

declare(strict_types=1);

namespace Vortos\Analytics\Capability;

use Vortos\OpsKit\Driver\Capability\CapabilityKey;

/**
 * The capabilities an analytics driver may declare — the single source of truth
 * validated at config time. The `null` driver declares all false; a real backend
 * (e.g. the PostHog split driver) declares true honestly only for what it actually
 * does (TCK-enforced honesty).
 */
enum AnalyticsCapability: string implements CapabilityKey
{
    case Batching = 'batching';
    case GroupAnalytics = 'group_analytics';
    case ServerSide = 'server_side';
    case OffHost = 'off_host';
    case IdentityMerge = 'identity_merge';

    public function key(): string
    {
        return $this->value;
    }
}
