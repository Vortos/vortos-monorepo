<?php

declare(strict_types=1);

namespace Vortos\Analytics\Runtime;

use Throwable;
use Vortos\Analytics\AnalyticsInterface;
use Vortos\Analytics\Event\AnalyticsEvent;
use Vortos\Analytics\Event\GroupAssociation;
use Vortos\Analytics\Event\IdentitySet;
use Vortos\Analytics\Privacy\PrivacyFilter;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

/**
 * Wraps the selected driver so it can **never** be reached without passing the
 * privacy filter first — the filter lives in the composition root, not optional
 * app discipline. Every call is defensively wrapped: a failure in the filter or
 * the inner driver is swallowed, never propagated (the never-throws contract holds
 * for the whole decorator chain, not just the bare driver).
 */
final class PrivacyFilteringAnalytics implements AnalyticsInterface
{
    public function __construct(
        private readonly AnalyticsInterface $inner,
        private readonly PrivacyFilter $filter,
    ) {}

    public function name(): string
    {
        return $this->inner->name();
    }

    public function capture(AnalyticsEvent $event): void
    {
        try {
            $filtered = $this->filter->apply($event);
            if ($filtered === null) {
                return;
            }
            $this->inner->capture($filtered);
        } catch (Throwable) {
            // Intentionally swallowed: privacy filtering must never become a second failure.
        }
    }

    public function identify(IdentitySet $identity): void
    {
        try {
            $filtered = $this->filter->applyIdentity($identity);
            if ($filtered === null) {
                return;
            }
            $this->inner->identify($filtered);
        } catch (Throwable) {
            // Intentionally swallowed.
        }
    }

    public function group(GroupAssociation $group): void
    {
        try {
            $filtered = $this->filter->applyGroup($group);
            if ($filtered === null) {
                return;
            }
            $this->inner->group($filtered);
        } catch (Throwable) {
            // Intentionally swallowed.
        }
    }

    public function flush(): void
    {
        try {
            $this->inner->flush();
        } catch (Throwable) {
            // Intentionally swallowed.
        }
    }

    public function capabilities(): CapabilityDescriptor
    {
        return $this->inner->capabilities();
    }
}
