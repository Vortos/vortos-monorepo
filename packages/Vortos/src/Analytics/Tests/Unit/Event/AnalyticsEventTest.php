<?php

declare(strict_types=1);

namespace Vortos\Analytics\Tests\Unit\Event;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Vortos\Analytics\Event\AnalyticsEvent;
use Vortos\Analytics\Event\DistinctId;

final class AnalyticsEventTest extends TestCase
{
    public function test_constructs_with_valid_fields(): void
    {
        $event = new AnalyticsEvent(new DistinctId('user-1'), 'signup', ['plan' => 'pro']);

        $this->assertSame('signup', $event->name);
        $this->assertSame(['plan' => 'pro'], $event->properties);
    }

    public function test_rejects_empty_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AnalyticsEvent(new DistinctId('user-1'), '');
    }

    public function test_rejects_name_over_max_length(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AnalyticsEvent(new DistinctId('user-1'), str_repeat('a', AnalyticsEvent::MAX_NAME_LENGTH + 1));
    }

    public function test_properties_with_10000_entries_is_truncated_to_max(): void
    {
        $huge = [];
        for ($i = 0; $i < 10000; $i++) {
            $huge['p' . $i] = $i;
        }

        $event = new AnalyticsEvent(new DistinctId('user-1'), 'flood', $huge);

        $this->assertLessThanOrEqual(AnalyticsEvent::MAX_PROPERTIES, count($event->properties));
    }

    public function test_oversized_single_value_is_capped_not_forwarded_raw(): void
    {
        $event = new AnalyticsEvent(new DistinctId('user-1'), 'big', ['blob' => str_repeat('x', 100000)]);

        $this->assertLessThanOrEqual(AnalyticsEvent::MAX_PROPERTY_BYTES, strlen((string) json_encode($event->properties)));
    }

    public function test_rejects_malformed_groups(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AnalyticsEvent(new DistinctId('user-1'), 'evt', [], null, ['' => 'acme']);
    }

    public function test_valid_groups_are_kept(): void
    {
        $event = new AnalyticsEvent(new DistinctId('user-1'), 'evt', [], null, ['org' => 'acme']);
        $this->assertSame(['org' => 'acme'], $event->groups);
    }

    public function test_from_array_builds_valid_event(): void
    {
        $event = AnalyticsEvent::fromArray([
            'distinctId' => 'user-1',
            'name' => 'signup',
            'properties' => ['plan' => 'pro'],
            'groups' => ['org' => 'acme'],
            'timestamp' => 1700000000,
        ]);

        $this->assertNotNull($event);
        $this->assertSame('user-1', $event->distinctId->value);
        $this->assertSame('signup', $event->name);
        $this->assertSame(['plan' => 'pro'], $event->properties);
        $this->assertSame(['org' => 'acme'], $event->groups);
        $this->assertNotNull($event->timestamp);
    }

    public function test_from_array_returns_null_on_missing_distinct_id(): void
    {
        $this->assertNull(AnalyticsEvent::fromArray(['name' => 'signup']));
    }

    public function test_from_array_returns_null_on_missing_name(): void
    {
        $this->assertNull(AnalyticsEvent::fromArray(['distinctId' => 'user-1']));
    }

    public function test_from_array_returns_null_on_empty_name(): void
    {
        $this->assertNull(AnalyticsEvent::fromArray(['distinctId' => 'user-1', 'name' => '']));
    }

    public function test_from_array_returns_null_on_non_string_distinct_id(): void
    {
        $this->assertNull(AnalyticsEvent::fromArray(['distinctId' => 123, 'name' => 'signup']));
    }

    public function test_from_array_skips_malformed_groups_without_failing(): void
    {
        $event = AnalyticsEvent::fromArray([
            'distinctId' => 'user-1',
            'name' => 'signup',
            'groups' => ['' => 'junk', 'org' => 'acme', 'bad' => 123],
        ]);

        $this->assertNotNull($event);
        $this->assertSame(['org' => 'acme'], $event->groups);
    }

    public function test_from_array_ignores_non_array_properties(): void
    {
        $event = AnalyticsEvent::fromArray(['distinctId' => 'user-1', 'name' => 'signup', 'properties' => 'not-an-array']);

        $this->assertNotNull($event);
        $this->assertSame([], $event->properties);
    }
}
