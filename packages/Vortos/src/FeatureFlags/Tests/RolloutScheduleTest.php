<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\FlagContext;
use Vortos\FeatureFlags\FlagEvaluator;
use Vortos\FeatureFlags\RolloutSchedule;

final class RolloutScheduleTest extends TestCase
{
    // --- ramp percentage math (unit) ---

    public function test_ramp_percentage_before_start_is_zero(): void
    {
        $schedule = $this->ramp([['2026-01-10', 5], ['2026-01-20', 100]]);
        $this->assertSame(0, $schedule->percentageAt(new \DateTimeImmutable('2026-01-01T00:00:00Z')));
    }

    public function test_ramp_percentage_at_first_stop(): void
    {
        $schedule = $this->ramp([['2026-01-10', 5], ['2026-01-20', 100]]);
        $this->assertSame(5, $schedule->percentageAt(new \DateTimeImmutable('2026-01-10T00:00:00Z')));
    }

    public function test_ramp_percentage_interpolates_midway(): void
    {
        // 5% → 100% over 10 days; halfway ≈ 52-53%.
        $schedule = $this->ramp([['2026-01-10T00:00:00Z', 5], ['2026-01-20T00:00:00Z', 100]]);
        $mid      = $schedule->percentageAt(new \DateTimeImmutable('2026-01-15T00:00:00Z'));
        $this->assertGreaterThanOrEqual(52, $mid);
        $this->assertLessThanOrEqual(53, $mid);
    }

    public function test_ramp_percentage_after_last_stop_is_ceiling(): void
    {
        $schedule = $this->ramp([['2026-01-10', 5], ['2026-01-20', 100]]);
        $this->assertSame(100, $schedule->percentageAt(new \DateTimeImmutable('2026-02-01T00:00:00Z')));
    }

    public function test_no_stops_returns_null_percentage(): void
    {
        $this->assertNull((new RolloutSchedule())->percentageAt(new \DateTimeImmutable('2026-01-01Z')));
    }

    // --- scheduled window ---

    public function test_enable_at_gates_before_window(): void
    {
        $schedule = new RolloutSchedule(enableAt: new \DateTimeImmutable('2026-01-10T00:00:00Z'));
        $this->assertFalse($schedule->isActiveAt(new \DateTimeImmutable('2026-01-09T23:59:59Z')));
        $this->assertTrue($schedule->isActiveAt(new \DateTimeImmutable('2026-01-10T00:00:00Z')));
    }

    public function test_disable_at_gates_after_window(): void
    {
        $schedule = new RolloutSchedule(disableAt: new \DateTimeImmutable('2026-01-10T00:00:00Z'));
        $this->assertTrue($schedule->isActiveAt(new \DateTimeImmutable('2026-01-09T23:59:59Z')));
        $this->assertFalse($schedule->isActiveAt(new \DateTimeImmutable('2026-01-10T00:00:00Z')));
    }

    // --- evaluator integration (frozen clock) ---

    public function test_scheduled_enable_transition_fires(): void
    {
        $schedule = new RolloutSchedule(enableAt: new \DateTimeImmutable('2026-06-01T00:00:00Z'));
        $flag     = $this->flag($schedule);

        $before = $this->evaluatorAt('2026-05-31T23:00:00Z');
        $after  = $this->evaluatorAt('2026-06-01T00:00:01Z');

        $this->assertFalse($before->evaluate($flag, new FlagContext('u')));
        $this->assertTrue($after->evaluate($flag, new FlagContext('u')));
    }

    public function test_scheduled_disable_transition_fires(): void
    {
        $schedule = new RolloutSchedule(disableAt: new \DateTimeImmutable('2026-06-01T00:00:00Z'));
        $flag     = $this->flag($schedule);

        $this->assertTrue($this->evaluatorAt('2026-05-31T23:00:00Z')->evaluate($flag, new FlagContext('u')));
        $this->assertFalse($this->evaluatorAt('2026-06-02T00:00:00Z')->evaluate($flag, new FlagContext('u')));
    }

    public function test_gradual_ramp_grows_over_time(): void
    {
        $schedule = $this->ramp([['2026-06-01T00:00:00Z', 0], ['2026-06-11T00:00:00Z', 100]]);
        $flag     = $this->flag($schedule);

        $countAt = function (string $instant) use ($flag): int {
            $ev = $this->evaluatorAt($instant);
            $in = 0;
            for ($i = 0; $i < 1000; $i++) {
                if ($ev->evaluate($flag, new FlagContext("user-{$i}"))) {
                    $in++;
                }
            }
            return $in;
        };

        $early = $countAt('2026-06-02T00:00:00Z'); // ~10%
        $mid   = $countAt('2026-06-06T00:00:00Z'); // ~50%
        $late  = $countAt('2026-06-10T00:00:00Z'); // ~90%

        $this->assertLessThan($mid, $early);
        $this->assertLessThan($late, $mid);
    }

    public function test_ramp_keeps_prior_cohort_as_it_grows(): void
    {
        // Sticky cohort across the ramp: anyone in at an earlier instant stays in later.
        $schedule = $this->ramp([['2026-06-01T00:00:00Z', 10], ['2026-06-11T00:00:00Z', 90]]);
        $flag     = $this->flag($schedule);

        $earlyEval = $this->evaluatorAt('2026-06-02T00:00:00Z');
        $lateEval  = $this->evaluatorAt('2026-06-10T00:00:00Z');

        for ($i = 0; $i < 1500; $i++) {
            $ctx = new FlagContext("user-{$i}");
            if ($earlyEval->evaluate($flag, $ctx)) {
                $this->assertTrue($lateEval->evaluate($flag, $ctx), "user-{$i} dropped out as the ramp grew");
            }
        }
    }

    public function test_dst_boundary_uses_absolute_instants(): void
    {
        // A US DST spring-forward night; comparisons are absolute instants, so no
        // off-by-one around the boundary.
        $schedule = new RolloutSchedule(enableAt: new \DateTimeImmutable('2026-03-08T07:00:00Z'));
        $flag     = $this->flag($schedule);

        $this->assertFalse($this->evaluatorAt('2026-03-08T06:59:59Z')->evaluate($flag, new FlagContext('u')));
        $this->assertTrue($this->evaluatorAt('2026-03-08T07:00:00Z')->evaluate($flag, new FlagContext('u')));
    }

    public function test_schedule_round_trips(): void
    {
        $schedule = new RolloutSchedule(
            enableAt: new \DateTimeImmutable('2026-06-01T00:00:00+00:00'),
            disableAt: new \DateTimeImmutable('2026-07-01T00:00:00+00:00'),
            stops: [
                ['at' => new \DateTimeImmutable('2026-06-01T00:00:00+00:00'), 'percentage' => 5],
                ['at' => new \DateTimeImmutable('2026-06-15T00:00:00+00:00'), 'percentage' => 100],
            ],
        );

        $restored = RolloutSchedule::fromArray($schedule->toArray());
        $this->assertEquals($schedule->enableAt, $restored->enableAt);
        $this->assertEquals($schedule->disableAt, $restored->disableAt);
        $this->assertCount(2, $restored->stops);
        $this->assertSame(100, $restored->stops[1]['percentage']);
    }

    /** @param array<int,array{0:string,1:int}> $stops */
    private function ramp(array $stops): RolloutSchedule
    {
        return new RolloutSchedule(stops: array_map(
            fn(array $s) => ['at' => new \DateTimeImmutable($s[0]), 'percentage' => $s[1]],
            $stops,
        ));
    }

    private function flag(RolloutSchedule $schedule): FeatureFlag
    {
        $now = new \DateTimeImmutable();
        return new FeatureFlag('id', 'scheduled', '', true, [], null, $now, $now, schedule: $schedule);
    }

    private function evaluatorAt(string $instant): FlagEvaluator
    {
        $clock = new class($instant) implements ClockInterface {
            public function __construct(private readonly string $instant) {}

            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable($this->instant);
            }
        };

        return new FlagEvaluator(clock: $clock);
    }
}
