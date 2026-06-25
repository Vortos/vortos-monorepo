<?php

declare(strict_types=1);

namespace Vortos\Analytics\Driver\Null;

use Vortos\Analytics\AnalyticsInterface;
use Vortos\Analytics\Capability\AnalyticsCapability;
use Vortos\Analytics\Event\AnalyticsEvent;
use Vortos\Analytics\Event\GroupAssociation;
use Vortos\Analytics\Event\IdentitySet;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

/**
 * The zero-dependency, zero-cost default. Every method is a true no-op — the base
 * install sends nothing, even before any privacy configuration exists.
 */
#[AsDriver('null')]
final class NullAnalytics implements AnalyticsInterface
{
    public function name(): string
    {
        return 'null';
    }

    public function capture(AnalyticsEvent $event): void
    {
        // Intentional no-op.
    }

    public function identify(IdentitySet $identity): void
    {
        // Intentional no-op.
    }

    public function group(GroupAssociation $group): void
    {
        // Intentional no-op.
    }

    public function flush(): void
    {
        // Intentional no-op.
    }

    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([
            AnalyticsCapability::Batching->value => false,
            AnalyticsCapability::GroupAnalytics->value => false,
            AnalyticsCapability::ServerSide->value => false,
            AnalyticsCapability::OffHost->value => false,
            AnalyticsCapability::IdentityMerge->value => false,
        ]);
    }
}
