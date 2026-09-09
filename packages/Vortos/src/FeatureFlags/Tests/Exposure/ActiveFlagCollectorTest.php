<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\Exposure;

use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Service\ResetInterface;
use Vortos\FeatureFlags\Exposure\ActiveFlagCollector;

final class ActiveFlagCollectorTest extends TestCase
{
    public function test_records_evaluations_in_order(): void
    {
        $collector = new ActiveFlagCollector();

        $collector->record('a', 'true');
        $collector->record('b', 'blue');

        $this->assertSame(['a' => 'true', 'b' => 'blue'], $collector->all());
    }

    public function test_re_evaluation_overwrites_with_the_more_current_answer(): void
    {
        $collector = new ActiveFlagCollector();

        $collector->record('a', 'false');
        $collector->record('a', 'true');

        $this->assertSame(['a' => 'true'], $collector->all());
    }

    public function test_null_variant_is_recorded_as_empty_string(): void
    {
        $collector = new ActiveFlagCollector();

        $collector->record('a', null);

        $this->assertSame(['a' => ''], $collector->all());
    }

    public function test_blank_flag_names_are_ignored(): void
    {
        $collector = new ActiveFlagCollector();

        $collector->record('', 'true');

        $this->assertTrue($collector->isEmpty());
    }

    public function test_is_bounded_and_reports_truncation(): void
    {
        $collector = new ActiveFlagCollector(maxFlags: 2);

        $collector->record('a', 'true');
        $collector->record('b', 'true');
        $collector->record('c', 'true');

        $this->assertSame(['a' => 'true', 'b' => 'true'], $collector->all());
        $this->assertTrue($collector->wasTruncated());
    }

    public function test_a_capped_collector_still_updates_flags_it_already_holds(): void
    {
        // Hitting the cap must not freeze the values already recorded — a flag re-evaluated
        // after the cap is reached would otherwise report a stale variant.
        $collector = new ActiveFlagCollector(maxFlags: 1);

        $collector->record('a', 'false');
        $collector->record('b', 'true');
        $collector->record('a', 'true');

        $this->assertSame(['a' => 'true'], $collector->all());
    }

    public function test_reset_clears_everything_between_requests(): void
    {
        // The worker-mode invariant: without this, one request's flags leak into the next
        // request's events and attribute them to the wrong variant.
        $collector = new ActiveFlagCollector(maxFlags: 1);
        $collector->record('a', 'true');
        $collector->record('b', 'true');

        $this->assertInstanceOf(ResetInterface::class, $collector, 'must be auto-collected by ResettableServicesPass');

        $collector->reset();

        $this->assertTrue($collector->isEmpty());
        $this->assertFalse($collector->wasTruncated());
    }
}
