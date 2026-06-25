<?php

declare(strict_types=1);

namespace Vortos\AnalyticsPosthog;

use Vortos\Analytics\Bridge\AnalyticsExposureObserver;
use Vortos\Analytics\Event\AnalyticsEvent;
use Vortos\Analytics\Event\GroupAssociation;
use Vortos\Analytics\Event\IdentitySet;

/**
 * Maps the agnostic Analytics VOs to PostHog's `/batch` wire shapes. **All PostHog
 * naming lives here, never in core** (§13 #1) — including the
 * `$feature_flag_called` event-name literal, which is the one place the agnostic
 * `feature_flag_exposure` bridge event (see {@see AnalyticsExposureObserver}) is
 * translated into PostHog's native experimentation event shape so PostHog's own
 * significance analysis can run on it. We never build a stats engine.
 */
final class PosthogEventMapper
{
    /** @return array<string,mixed> */
    public function mapEvent(AnalyticsEvent $event): array
    {
        if ($event->name === AnalyticsExposureObserver::EVENT_NAME) {
            return $this->mapFeatureFlagCalled($event);
        }

        $properties = $event->properties;
        $properties['distinct_id'] = $event->distinctId->value;

        foreach ($event->groups as $groupType => $groupKey) {
            $properties['$groups'][$groupType] = $groupKey;
        }

        $item = [
            'event' => $event->name,
            'properties' => $properties,
        ];

        if ($event->timestamp !== null) {
            $item['timestamp'] = $event->timestamp->format('c');
        }

        return $item;
    }

    /** @return array<string,mixed> */
    public function mapIdentity(IdentitySet $identity): array
    {
        return [
            'event' => '$identify',
            'properties' => [
                'distinct_id' => $identity->distinctId->value,
                '$set' => $identity->traits,
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function mapGroup(GroupAssociation $group): array
    {
        return [
            'event' => '$groupidentify',
            'distinct_id' => $group->distinctId->value,
            'properties' => [
                '$group_type' => $group->groupType,
                '$group_key' => $group->groupKey,
                '$group_set' => $group->traits,
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function mapFeatureFlagCalled(AnalyticsEvent $event): array
    {
        return [
            'event' => '$feature_flag_called',
            'properties' => [
                'distinct_id' => $event->distinctId->value,
                '$feature_flag' => $event->properties['flag'] ?? '',
                '$feature_flag_response' => $event->properties['variant'] ?? '',
            ],
        ];
    }
}
