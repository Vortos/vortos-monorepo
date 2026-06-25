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
}
