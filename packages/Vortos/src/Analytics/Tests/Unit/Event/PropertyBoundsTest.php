<?php

declare(strict_types=1);

namespace Vortos\Analytics\Tests\Unit\Event;

use PHPUnit\Framework\TestCase;
use Vortos\Analytics\Event\PropertyBounds;

final class PropertyBoundsTest extends TestCase
{
    public function test_returns_input_unchanged_when_within_bounds(): void
    {
        $props = ['a' => 1, 'b' => 'two'];
        $this->assertSame($props, PropertyBounds::bound($props, 100, 16384));
    }

    public function test_truncates_by_count_deterministically(): void
    {
        $props = ['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4];
        $bounded = PropertyBounds::bound($props, 2, 16384);

        $this->assertSame(['a' => 1, 'b' => 2], $bounded, 'must keep the first N keys in original order, never throw');
    }

    public function test_drops_non_string_or_empty_keys(): void
    {
        $props = ['' => 'junk', 'valid' => 'ok', 0 => 'numeric-key-junk'];
        $bounded = PropertyBounds::bound($props, 100, 16384);

        $this->assertSame(['valid' => 'ok'], $bounded);
    }

    public function test_truncates_by_serialized_size_dropping_from_tail(): void
    {
        $props = [
            'a' => str_repeat('x', 50),
            'b' => str_repeat('y', 50),
            'c' => str_repeat('z', 50),
        ];

        // Budget only large enough for the first key's JSON encoding.
        $encodedFirst = json_encode(['a' => $props['a']]);
        $bounded = PropertyBounds::bound($props, 100, strlen($encodedFirst));

        $this->assertSame(['a' => $props['a']], $bounded);
    }

    public function test_extreme_flood_of_properties_is_bounded_never_thrown(): void
    {
        $huge = [];
        for ($i = 0; $i < 10000; $i++) {
            $huge['key' . $i] = 'value' . $i;
        }

        $bounded = PropertyBounds::bound($huge, 100, 16384);

        $this->assertLessThanOrEqual(100, count($bounded));
        $this->assertLessThanOrEqual(16384, strlen((string) json_encode($bounded)));
    }

    public function test_empty_properties_bound_to_empty(): void
    {
        $this->assertSame([], PropertyBounds::bound([], 100, 16384));
    }
}
