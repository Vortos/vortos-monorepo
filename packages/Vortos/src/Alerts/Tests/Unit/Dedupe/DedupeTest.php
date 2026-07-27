<?php

declare(strict_types=1);

namespace Vortos\Alerts\Tests\Unit\Dedupe;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Vortos\Alerts\Dedupe\Dedupe;
use Vortos\Alerts\Dedupe\DedupeDecision;
use Vortos\Alerts\Dedupe\DedupeWindow;
use Vortos\Alerts\Dedupe\ReminderBackoff;
use Vortos\Alerts\Event\AlertEvent;
use Vortos\Alerts\Event\AlertSource;
use Vortos\Alerts\Severity;

final class DedupeTest extends TestCase
{
    private function event(DateTimeImmutable $at): AlertEvent
    {
        return AlertEvent::scrubbed(
            ruleId: 'storm-rule',
            severity: Severity::Critical,
            title: 't',
            summary: 's',
            source: AlertSource::Health,
            env: 'prod',
            tenantId: null,
            labels: ['host' => 'a'],
            annotations: [],
            links: [],
            occurredAt: $at,
        );
    }

    public function test_synthetic_storm_of_identical_alerts_collapses_to_one_notification(): void
    {
        // A backoff far longer than the simulated window, so no reminder interferes here.
        $dedupe = new Dedupe(new ReminderBackoff(initialSeconds: 86_400, maxSeconds: 86_400));
        $window = new DedupeWindow(300);
        $now = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        $state = null;
        $newCount = 0;
        $dedupedCount = 0;

        for ($i = 0; $i < 50; $i++) {
            $at = $now->modify("+{$i} seconds");
            $outcome = $dedupe->evaluate($this->event($at), $state, $window, $at);
            $state = $outcome->nextState;

            match ($outcome->decision) {
                DedupeDecision::New => $newCount++,
                DedupeDecision::Deduped => $dedupedCount++,
                DedupeDecision::Digest => null,
            };
        }

        self::assertSame(1, $newCount, 'exactly one outbound "new" notification for the whole storm');
        self::assertSame(49, $dedupedCount);
        self::assertSame(50, $state->occurrenceCount);
    }

    public function test_reminders_widen_instead_of_repeating_forever(): void
    {
        // THE REGRESSION. Reminders used to fire every Nth OCCURRENCE. Sources are evaluated on a
        // fixed cadence, so that is a fixed time interval that never stops — production ran six
        // permanently-open alerts at ~36 Slack messages an hour, indefinitely. A muted channel is
        // the guaranteed outcome, and a muted channel silences the next real alert too.
        //
        // Reminders now double: 10m, 20m, 40m, 80m... so a long outage yields a handful of
        // messages rather than hundreds.
        $dedupe = new Dedupe(new ReminderBackoff(initialSeconds: 600, maxSeconds: 21_600));
        $window = new DedupeWindow(3_600);
        $start = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        $state = null;
        $reminderMinutes = [];

        // Six hours of a condition observed every minute — 360 evaluations.
        for ($minute = 0; $minute < 360; $minute++) {
            $at = $start->modify("+{$minute} minutes");
            $outcome = $dedupe->evaluate($this->event($at), $state, $window, $at);
            $state = $outcome->nextState;

            if ($outcome->decision === DedupeDecision::Digest) {
                $reminderMinutes[] = $minute;
            }
        }

        // 10 + 20 + 40 + 80 + 160 minutes after the initial announcement.
        self::assertSame([10, 30, 70, 150, 310], $reminderMinutes);

        // The old behaviour would have produced 36 in the same window.
        self::assertLessThan(
            10,
            \count($reminderMinutes),
            'six hours of a firing alert must not produce dozens of pages',
        );
    }

    public function test_window_expiry_resets_to_new(): void
    {
        $dedupe = new Dedupe();
        $window = new DedupeWindow(60);
        $now = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        $first = $dedupe->evaluate($this->event($now), null, $window, $now);
        $later = $now->modify('+120 seconds');
        $second = $dedupe->evaluate($this->event($later), $first->nextState, $window, $later);

        self::assertSame(DedupeDecision::New, $second->decision);
        self::assertSame(1, $second->nextState->occurrenceCount);
    }
}
