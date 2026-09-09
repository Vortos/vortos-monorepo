<?php

declare(strict_types=1);

namespace Vortos\Analytics\Bridge;

use Throwable;
use Vortos\Analytics\AnalyticsInterface;
use Vortos\Analytics\Event\AnalyticsEvent;
use Vortos\Analytics\Event\GroupAssociation;
use Vortos\Analytics\Event\IdentitySet;
use Vortos\Analytics\Privacy\ReservedProperties;
use Vortos\FeatureFlags\Exposure\ActiveFlagSourceInterface;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

/**
 * Stamps the request's evaluated flags onto **every** analytics event, as the reserved
 * {@see ReservedProperties::ACTIVE_FLAGS} property.
 *
 * ## Why every event, not just the exposure event
 *
 * An exposure event alone answers "how often was this flag called, and what did it
 * return". It cannot answer the question anyone actually has — "does the flag change the
 * metric I care about" — because the metric lives on a *different* event (a signup, a
 * payment, a submission) that carries no flag attribution. Analytics backends solve this
 * by requiring the flag value to travel on the metric event itself; PostHog's convention
 * for externally-evaluated flags is exactly that. Stamping it centrally here means every
 * event an app emits gains flag attribution without a single call site changing, and
 * without app code having to remember.
 *
 * Provider-agnostic by construction: the reserved property is a plain
 * `flag => variant` map. Translating it into a backend's naming (PostHog's
 * `$feature/<key>` and `$active_feature_flags`) is the driver's mapping job — no provider
 * vocabulary appears in core.
 *
 * ## Ordering
 *
 * Sits **outermost**, above privacy filtering and batching, so app-issued events are
 * enriched before the privacy filter runs — which is why the property is reserved there
 * rather than left to each app's allowlist. It is registered only when FeatureFlags is
 * installed; without it, nothing in this file is wired and the chain is unchanged.
 *
 * Never throws, and never suppresses: if enrichment fails the original event is still
 * forwarded. Losing attribution is acceptable; losing the event is not.
 */
final class FlagEnrichingAnalytics implements AnalyticsInterface
{
    public function __construct(
        private readonly AnalyticsInterface $inner,
        private readonly ActiveFlagSourceInterface $collector,
        private readonly bool $enabled = false,
    ) {}

    public function name(): string
    {
        return $this->inner->name();
    }

    public function capture(AnalyticsEvent $event): void
    {
        $this->inner->capture($this->enrich($event));
    }

    /**
     * Identity traits carry the same map, so the backend can maintain a person-level
     * "flags this user is in" property for cohort building.
     */
    public function identify(IdentitySet $identity): void
    {
        $active = $this->activeFlags();

        if ($active === [] || array_key_exists(ReservedProperties::ACTIVE_FLAGS, $identity->traits)) {
            $this->inner->identify($identity);

            return;
        }

        try {
            $this->inner->identify(new IdentitySet(
                $identity->distinctId,
                [ReservedProperties::ACTIVE_FLAGS => $active] + $identity->traits,
            ));
        } catch (Throwable) {
            $this->inner->identify($identity);
        }
    }

    public function group(GroupAssociation $group): void
    {
        $this->inner->group($group);
    }

    public function flush(): void
    {
        $this->inner->flush();
    }

    public function capabilities(): CapabilityDescriptor
    {
        return $this->inner->capabilities();
    }

    private function enrich(AnalyticsEvent $event): AnalyticsEvent
    {
        $active = $this->activeFlags();

        // An explicit value from the caller always wins — enrichment describes the
        // request, and a caller reconstructing a historical event knows better.
        if ($active === [] || array_key_exists(ReservedProperties::ACTIVE_FLAGS, $event->properties)) {
            return $event;
        }

        try {
            return new AnalyticsEvent(
                distinctId: $event->distinctId,
                name:       $event->name,
                // Prepended, not appended: AnalyticsEvent bounds oversized property bags by
                // dropping from the tail, so appending would make attribution the first
                // thing lost on exactly the events that carry the most context.
                properties: [ReservedProperties::ACTIVE_FLAGS => $active] + $event->properties,
                timestamp:  $event->timestamp,
                groups:     $event->groups,
            );
        } catch (Throwable) {
            return $event;
        }
    }

    /** @return array<string,string> */
    private function activeFlags(): array
    {
        if (!$this->enabled) {
            return [];
        }

        try {
            return $this->collector->all();
        } catch (Throwable) {
            return [];
        }
    }
}
