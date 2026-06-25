<?php

declare(strict_types=1);

namespace Vortos\Alerts\Tests\Unit\Dedupe;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Vortos\Alerts\Dedupe\AlertState;
use Vortos\Alerts\Dedupe\DedupeWindow;
use Vortos\Alerts\Dedupe\FlapDamper;

final class FlapDamperTest extends TestCase
{
    public function test_flapping_signal_escalates_once_then_damps(): void
    {
        $damper = new FlapDamper(maxTransitions: 3);
        $window = new DedupeWindow(600);
        $now = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        $state = AlertState::firstSeen('fp', $now);

        $escalations = 0;
        $damped = 0;

        // Simulate open -> resolve -> open, 6 times within the window.
        for ($i = 1; $i <= 6; $i++) {
            $at = $now->modify("+{$i} minutes");
            $outcome = $damper->recordTransition($state, $at, $window);
            $state = $outcome->nextState;

            if ($outcome->shouldEscalate) {
                $escalations++;
            }
            if ($outcome->isDamped) {
                $damped++;
            }
        }

        self::assertSame(1, $escalations, 'a flapping signal must escalate exactly once');
        self::assertGreaterThan(0, $damped, 'subsequent toggles after escalation must be damped, not re-escalated');
    }

    public function test_below_threshold_never_escalates(): void
    {
        $damper = new FlapDamper(maxTransitions: 10);
        $window = new DedupeWindow(600);
        $now = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $state = AlertState::firstSeen('fp', $now);

        for ($i = 1; $i <= 3; $i++) {
            $outcome = $damper->recordTransition($state, $now->modify("+{$i} minutes"), $window);
            $state = $outcome->nextState;
            self::assertFalse($outcome->shouldEscalate);
        }
    }

    public function test_new_window_after_expiry_resets_transition_count(): void
    {
        $damper = new FlapDamper(maxTransitions: 2);
        $window = new DedupeWindow(60);
        $now = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $state = AlertState::firstSeen('fp', $now);

        $first = $damper->recordTransition($state, $now, $window);
        $second = $damper->recordTransition($first->nextState, $now->modify('+1000 seconds'), $window);

        self::assertSame(1, $second->nextState->flapTransitions, 'a fresh window must reset the transition count');
        self::assertFalse($second->shouldEscalate);
    }
}
