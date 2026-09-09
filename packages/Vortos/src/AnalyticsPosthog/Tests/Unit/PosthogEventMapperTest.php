<?php

declare(strict_types=1);

namespace Vortos\AnalyticsPosthog\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Vortos\Analytics\Bridge\AnalyticsExposureObserver;
use Vortos\Analytics\Event\AnalyticsEvent;
use Vortos\Analytics\Event\DistinctId;
use Vortos\Analytics\Event\GroupAssociation;
use Vortos\Analytics\Event\IdentitySet;
use Vortos\Analytics\Privacy\ReservedProperties;
use Vortos\AnalyticsPosthog\PosthogEventMapper;

final class PosthogEventMapperTest extends TestCase
{
    public function test_maps_capture_event(): void
    {
        $mapper = new PosthogEventMapper();
        $item = $mapper->mapEvent(new AnalyticsEvent(new DistinctId('user-1'), 'signup', ['plan' => 'pro']));

        $this->assertSame('signup', $item['event']);
        $this->assertSame('user-1', $item['properties']['distinct_id']);
        $this->assertSame('pro', $item['properties']['plan']);
    }

    public function test_maps_event_groups_to_dollar_groups(): void
    {
        $mapper = new PosthogEventMapper();
        $item = $mapper->mapEvent(new AnalyticsEvent(new DistinctId('user-1'), 'evt', [], null, ['org' => 'acme']));

        $this->assertSame('acme', $item['properties']['$groups']['org']);
    }

    public function test_maps_timestamp_to_iso8601(): void
    {
        $mapper = new PosthogEventMapper();
        $item = $mapper->mapEvent(new AnalyticsEvent(new DistinctId('user-1'), 'evt', [], new DateTimeImmutable('2026-01-01T00:00:00+00:00')));

        $this->assertSame('2026-01-01T00:00:00+00:00', $item['timestamp']);
    }

    public function test_maps_feature_flag_exposure_to_posthog_native_shape(): void
    {
        $mapper = new PosthogEventMapper();
        $event = new AnalyticsEvent(new DistinctId('ctx-1'), AnalyticsExposureObserver::EVENT_NAME, ['flag' => 'checkout', 'variant' => 'b']);

        $item = $mapper->mapEvent($event);

        $this->assertSame('$feature_flag_called', $item['event']);
        $this->assertSame('ctx-1', $item['properties']['distinct_id']);
        $this->assertSame('checkout', $item['properties']['$feature_flag']);
        $this->assertSame('b', $item['properties']['$feature_flag_response']);
        $this->assertArrayNotHasKey('flag', $item['properties'], 'no agnostic property names should leak into the posthog-native shape');
    }

    public function test_maps_identity_to_dollar_identify(): void
    {
        $mapper = new PosthogEventMapper();
        $item = $mapper->mapIdentity(new IdentitySet(new DistinctId('user-1'), ['plan' => 'pro']));

        $this->assertSame('$identify', $item['event']);
        $this->assertSame('user-1', $item['properties']['distinct_id']);
        $this->assertSame(['plan' => 'pro'], $item['properties']['$set']);
    }

    public function test_maps_group_to_dollar_groupidentify(): void
    {
        $mapper = new PosthogEventMapper();
        $item = $mapper->mapGroup(new GroupAssociation(new DistinctId('user-1'), 'org', 'acme', ['seats' => 10]));

        $this->assertSame('$groupidentify', $item['event']);
        $this->assertSame('user-1', $item['distinct_id']);
        $this->assertSame('org', $item['properties']['$group_type']);
        $this->assertSame('acme', $item['properties']['$group_key']);
        $this->assertSame(['seats' => 10], $item['properties']['$group_set']);
    }
    public function test_exposure_carries_the_feature_property_experiments_read(): void
    {
        // The $feature_flag pair drives the flag's Usage tab; $feature/<key> is what an
        // Experiment reads for attribution. Emitting only the pair leaves experiment results
        // blank for a flag PostHog does not itself evaluate.
        $mapper = new PosthogEventMapper();
        $event = new AnalyticsEvent(new DistinctId('ctx-1'), AnalyticsExposureObserver::EVENT_NAME, ['flag' => 'checkout', 'variant' => 'b']);

        $item = $mapper->mapEvent($event);

        $this->assertSame('b', $item['properties']['$feature/checkout']);
    }

    public function test_boolean_flag_response_is_a_real_boolean_not_a_string(): void
    {
        // PostHog branches on the type: the string "true" reads as a *variant name*, not an
        // on/off value, which silently turns every boolean flag into a one-variant experiment.
        $mapper = new PosthogEventMapper();

        $on = $mapper->mapEvent(new AnalyticsEvent(new DistinctId('c'), AnalyticsExposureObserver::EVENT_NAME, ['flag' => 'f', 'variant' => 'true']));
        $off = $mapper->mapEvent(new AnalyticsEvent(new DistinctId('c'), AnalyticsExposureObserver::EVENT_NAME, ['flag' => 'f', 'variant' => 'false']));

        $this->assertTrue($on['properties']['$feature_flag_response']);
        $this->assertTrue($on['properties']['$feature/f']);
        $this->assertFalse($off['properties']['$feature_flag_response']);
        $this->assertFalse($off['properties']['$feature/f']);
    }

    public function test_multivariate_response_stays_a_string(): void
    {
        $mapper = new PosthogEventMapper();

        $item = $mapper->mapEvent(new AnalyticsEvent(new DistinctId('c'), AnalyticsExposureObserver::EVENT_NAME, ['flag' => 'f', 'variant' => 'blue']));

        $this->assertSame('blue', $item['properties']['$feature_flag_response']);
    }

    public function test_exposure_keeps_groups_and_timestamp(): void
    {
        // Both were dropped by the old exposure mapping: no per-tenant breakdown, and every
        // exposure stamped at ingest time rather than when it happened.
        $mapper = new PosthogEventMapper();
        $event = new AnalyticsEvent(
            new DistinctId('ctx-1'),
            AnalyticsExposureObserver::EVENT_NAME,
            ['flag' => 'checkout', 'variant' => 'b', 'exposure_source' => 'server'],
            new DateTimeImmutable('2026-01-02T03:04:05+00:00'),
            ['organization' => 'org-7'],
        );

        $item = $mapper->mapEvent($event);

        $this->assertSame(['organization' => 'org-7'], $item['properties']['$groups']);
        $this->assertSame('2026-01-02T03:04:05+00:00', $item['timestamp']);
        $this->assertSame('server', $item['properties']['exposure_source']);
    }

    public function test_active_flags_are_expanded_onto_every_event(): void
    {
        // The reason any insight can be broken down by flag: attribution travels on the
        // metric event, not only on the exposure.
        $mapper = new PosthogEventMapper();
        $event = new AnalyticsEvent(new DistinctId('u1'), 'payment_succeeded', [
            ReservedProperties::ACTIVE_FLAGS => ['checkout' => 'blue', 'kill_switch' => 'false'],
            'amount' => 100,
        ]);

        $item = $mapper->mapEvent($event);

        $this->assertSame('blue', $item['properties']['$feature/checkout']);
        $this->assertFalse($item['properties']['$feature/kill_switch']);
        $this->assertSame(100, $item['properties']['amount']);
        $this->assertArrayNotHasKey(ReservedProperties::ACTIVE_FLAGS, $item['properties'], 'the internal key must never reach PostHog');
    }

    public function test_identify_sets_active_feature_flags_for_cohorts(): void
    {
        $mapper = new PosthogEventMapper();
        $identity = new IdentitySet(new DistinctId('u1'), [
            ReservedProperties::ACTIVE_FLAGS => ['checkout' => 'blue', 'off_flag' => 'false', 'on_flag' => 'true'],
            'plan' => 'pro',
        ]);

        $item = $mapper->mapIdentity($identity);

        // Only flags the user is actually in — an off flag is not an enrolment.
        $this->assertSame(['checkout', 'on_flag'], $item['properties']['$set']['$active_feature_flags']);
        $this->assertSame('pro', $item['properties']['$set']['plan']);
        $this->assertArrayNotHasKey(ReservedProperties::ACTIVE_FLAGS, $item['properties']['$set']);
    }

    public function test_group_traits_drop_the_internal_active_flags_key(): void
    {
        $mapper = new PosthogEventMapper();
        $group = new GroupAssociation(new DistinctId('u1'), 'organization', 'org-7', [
            ReservedProperties::ACTIVE_FLAGS => ['checkout' => 'blue'],
        ]);

        $item = $mapper->mapGroup($group);

        $this->assertArrayNotHasKey(ReservedProperties::ACTIVE_FLAGS, $item['properties']['$group_set']);
    }

    public function test_malformed_active_flags_are_ignored_not_fatal(): void
    {
        $mapper = new PosthogEventMapper();

        $item = $mapper->mapEvent(new AnalyticsEvent(new DistinctId('u1'), 'e', [
            ReservedProperties::ACTIVE_FLAGS => 'not-an-array',
        ]));

        $this->assertArrayNotHasKey(ReservedProperties::ACTIVE_FLAGS, $item['properties']);
    }
}
