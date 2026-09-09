<?php

declare(strict_types=1);

namespace Vortos\AnalyticsPosthog;

use Vortos\Analytics\Bridge\AnalyticsExposureObserver;
use Vortos\Analytics\Event\AnalyticsEvent;
use Vortos\Analytics\Event\GroupAssociation;
use Vortos\Analytics\Event\IdentitySet;
use Vortos\Analytics\Privacy\ReservedProperties;

/**
 * Maps the agnostic Analytics VOs to PostHog's `/batch` wire shapes. **All PostHog
 * naming lives here, never in core** (§13 #1).
 *
 * ## Feature-flag analytics
 *
 * PostHog reads flag data through three separate conventions, and needs all three to
 * light up the whole product — one of them alone leaves most of the UI empty:
 *
 *  - **`$feature_flag_called`** with `$feature_flag` / `$feature_flag_response` populates
 *    a flag's **Usage** tab ("how often is it called, and what does it return").
 *  - **`$feature/<key>` on any event** is PostHog's documented convention for flags
 *    evaluated *outside* PostHog. It is what attributes an arbitrary metric event to a
 *    variant, and therefore what makes experiment results and "break this insight down by
 *    flag" work at all. Stamped on every event from the reserved active-flags property.
 *  - **`$active_feature_flags`** as a person property backs cohort building
 *    ("users currently in the new-checkout flag").
 *
 * We never build a stats engine — PostHog does the significance analysis. Our job is to
 * hand it data in the shapes it already knows how to read.
 */
final class PosthogEventMapper
{
    /** PostHog's per-flag event property prefix for externally-evaluated flags. */
    private const FEATURE_PREFIX = '$feature/';

    /**
     * How a boolean evaluation is rendered on the wire by `ExposureRecord`. Duplicated as
     * literals rather than imported: this mapper ships in the analytics driver, which must
     * load even when the feature-flags package is not installed at all.
     */
    private const BOOL_TRUE  = 'true';
    private const BOOL_FALSE = 'false';

    /** @return array<string,mixed> */
    public function mapEvent(AnalyticsEvent $event): array
    {
        if ($event->name === AnalyticsExposureObserver::EVENT_NAME) {
            return $this->mapFeatureFlagCalled($event);
        }

        $properties = $event->properties;
        $activeFlags = $this->takeActiveFlags($properties);

        $properties['distinct_id'] = $event->distinctId->value;
        $properties += $this->featureProperties($activeFlags);

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
        $traits = $identity->traits;
        $activeFlags = $this->takeActiveFlags($traits);

        $enabled = $this->enabledFlagKeys($activeFlags);
        if ($enabled !== []) {
            $traits['$active_feature_flags'] = $enabled;
        }

        return [
            'event' => '$identify',
            'properties' => [
                'distinct_id' => $identity->distinctId->value,
                '$set' => $traits,
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function mapGroup(GroupAssociation $group): array
    {
        $traits = $group->traits;
        $this->takeActiveFlags($traits);

        return [
            'event' => '$groupidentify',
            'distinct_id' => $group->distinctId->value,
            'properties' => [
                '$group_type' => $group->groupType,
                '$group_key' => $group->groupKey,
                '$group_set' => $traits,
            ],
        ];
    }

    /**
     * The exposure event, in PostHog's native experimentation shape.
     *
     * Carries `$feature/<key>` alongside the `$feature_flag*` pair on purpose: the pair
     * drives the flag's Usage tab, while the `$feature/<key>` property is what an
     * experiment reads for attribution. Emitting only the pair leaves experiment results
     * blank for a flag PostHog does not itself evaluate.
     *
     * @return array<string,mixed>
     */
    private function mapFeatureFlagCalled(AnalyticsEvent $event): array
    {
        $propertyBag = $event->properties;
        $activeFlags = $this->takeActiveFlags($propertyBag);

        $flag = $this->stringProperty($event->properties, 'flag');
        $variant = $this->stringProperty($event->properties, 'variant');

        $properties = [
            'distinct_id' => $event->distinctId->value,
            '$feature_flag' => $flag,
            '$feature_flag_response' => $this->flagResponse($variant),
        ];

        if ($flag !== '') {
            $properties[self::FEATURE_PREFIX . $flag] = $this->flagResponse($variant);
        }

        $source = $this->stringProperty($event->properties, 'exposure_source');
        if ($source !== '') {
            // Not a PostHog convention — a plain property, so a browser-reported exposure
            // can be told apart from a server-side gate when reading the numbers.
            $properties['exposure_source'] = $source;
        }

        // Flags evaluated elsewhere in the same request still travel, so an exposure is
        // filterable by the rest of the request's flag state.
        $properties += $this->featureProperties($activeFlags, $flag);

        foreach ($event->groups as $groupType => $groupKey) {
            $properties['$groups'][$groupType] = $groupKey;
        }

        $item = [
            'event' => '$feature_flag_called',
            'properties' => $properties,
        ];

        if ($event->timestamp !== null) {
            $item['timestamp'] = $event->timestamp->format('c');
        }

        return $item;
    }

    /**
     * Pull the framework's reserved active-flags map out of a property bag, removing it
     * so the internal key never reaches PostHog as a literal property.
     *
     * @param array<string,mixed> $properties
     * @return array<string,string>
     */
    private function takeActiveFlags(array &$properties): array
    {
        $raw = $properties[ReservedProperties::ACTIVE_FLAGS] ?? null;
        unset($properties[ReservedProperties::ACTIVE_FLAGS]);

        if (!is_array($raw)) {
            return [];
        }

        $flags = [];
        foreach ($raw as $flag => $variant) {
            if (is_string($flag) && $flag !== '' && (is_string($variant) || is_bool($variant))) {
                $flags[$flag] = is_bool($variant) ? ($variant ? 'true' : 'false') : $variant;
            }
        }

        return $flags;
    }

    /**
     * `$feature/<key>` properties for each evaluated flag.
     *
     * @param array<string,string> $activeFlags
     * @return array<string,bool|string>
     */
    private function featureProperties(array $activeFlags, ?string $skip = null): array
    {
        $properties = [];

        foreach ($activeFlags as $flag => $variant) {
            if ($flag === $skip) {
                continue;
            }
            $properties[self::FEATURE_PREFIX . $flag] = $this->flagResponse($variant);
        }

        return $properties;
    }

    /**
     * The flag keys a person is currently "in" — everything that evaluated to something
     * other than off. PostHog treats `$active_feature_flags` as a list of keys.
     *
     * @param array<string,string> $activeFlags
     * @return list<string>
     */
    private function enabledFlagKeys(array $activeFlags): array
    {
        $keys = [];

        foreach ($activeFlags as $flag => $variant) {
            if ($variant !== self::BOOL_FALSE && $variant !== '') {
                $keys[] = $flag;
            }
        }

        return $keys;
    }

    /**
     * A boolean flag must reach PostHog as a real JSON boolean, not the string `"true"` —
     * PostHog's flag UI and experiment maths both branch on the type, and a string
     * silently reads as a *variant name* instead of an on/off value. Multivariate results
     * stay strings, which is what a variant key is.
     */
    private function flagResponse(string $variant): bool|string
    {
        return match ($variant) {
            self::BOOL_TRUE  => true,
            self::BOOL_FALSE => false,
            default                    => $variant,
        };
    }

    /** @param array<string,mixed> $properties */
    private function stringProperty(array $properties, string $key): string
    {
        $value = $properties[$key] ?? '';

        return is_string($value) ? $value : '';
    }
}
