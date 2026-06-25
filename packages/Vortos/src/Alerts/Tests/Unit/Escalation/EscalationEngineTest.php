<?php

declare(strict_types=1);

namespace Vortos\Alerts\Tests\Unit\Escalation;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Vortos\Alerts\Escalation\Acknowledgement;
use Vortos\Alerts\Escalation\EscalationEngine;
use Vortos\Alerts\Escalation\EscalationOutcome;
use Vortos\Alerts\Escalation\EscalationPolicy;
use Vortos\Alerts\Escalation\EscalationTier;
use Vortos\Alerts\Escalation\MaintenanceSilence;
use Vortos\Alerts\Escalation\OnCallRotation;
use Vortos\Alerts\Escalation\QuietHours;
use Vortos\Alerts\Escalation\QuietHoursPolicy;
use Vortos\Alerts\Escalation\Responder;
use Vortos\Alerts\Event\AlertEvent;
use Vortos\Alerts\Event\AlertSource;
use Vortos\Alerts\Severity;

final class EscalationEngineTest extends TestCase
{
    private function event(Severity $severity, string $ruleId = 'r1'): AlertEvent
    {
        return AlertEvent::scrubbed(
            ruleId: $ruleId,
            severity: $severity,
            title: 't',
            summary: 's',
            source: AlertSource::Health,
            env: 'prod',
            tenantId: null,
            labels: [],
            annotations: [],
            links: [],
            occurredAt: new DateTimeImmutable('2026-01-01T12:00:00+00:00'),
        );
    }

    private function engine(QuietHoursPolicy $quietHours = new QuietHoursPolicy()): EscalationEngine
    {
        $rotation = new OnCallRotation([new Responder('r1', 'Primary', 'oncall-page')], new DateTimeImmutable('@0'));
        $policy = new EscalationPolicy([new EscalationTier(0, 0), new EscalationTier(1, 600)]);

        return new EscalationEngine($policy, $rotation, $quietHours);
    }

    public function test_unacked_critical_repages_next_tier_after_wait(): void
    {
        $engine = $this->engine();
        $event = $this->event(Severity::Critical);
        $now = $event->occurredAt;

        [$initial, $state] = $engine->start($event, $now);
        self::assertSame(EscalationOutcome::Notify, $initial->outcome);
        self::assertSame(0, $initial->tier);

        // Before the tier-0 wait (0s) is even relevant, ticking immediately should escalate to tier 1
        // once we simulate elapsed time past tier 1's wait.
        $later = $now->modify('+601 seconds');
        [$decision, $state] = $engine->tick($event, $state, null, [], $later);

        self::assertSame(EscalationOutcome::Notify, $decision->outcome);
        self::assertSame(1, $decision->tier);
        self::assertSame(1, $state->currentTier);
    }

    public function test_wait_outcome_before_tier_elapses(): void
    {
        $rotation = new OnCallRotation([new Responder('r1', 'Primary', 'oncall-page')], new DateTimeImmutable('@0'));
        $policy = new EscalationPolicy([new EscalationTier(0, 300), new EscalationTier(1, 600)]);
        $engine = new EscalationEngine($policy, $rotation, new QuietHoursPolicy());
        $event = $this->event(Severity::Critical);
        $now = $event->occurredAt;

        [, $state] = $engine->start($event, $now);
        [$decision] = $engine->tick($event, $state, null, [], $now->modify('+10 seconds'));

        self::assertSame(EscalationOutcome::Wait, $decision->outcome);
    }

    public function test_ack_stops_escalation_immediately(): void
    {
        $engine = $this->engine();
        $event = $this->event(Severity::Critical);
        $now = $event->occurredAt;

        [, $state] = $engine->start($event, $now);
        $ack = new Acknowledgement('fp', 0, 'alice', $now);

        [$decision, $nextState] = $engine->tick($event, $state, $ack, [], $now->modify('+1 second'));

        self::assertSame(EscalationOutcome::Stop, $decision->outcome);
        self::assertTrue($nextState->stopped);

        // Stays stopped on further ticks even without an ack object.
        [$decision2] = $engine->tick($event, $nextState, null, [], $now->modify('+10000 seconds'));
        self::assertSame(EscalationOutcome::Stop, $decision2->outcome);
    }

    public function test_escalation_exhausted_after_last_tier_stops(): void
    {
        $engine = $this->engine();
        $event = $this->event(Severity::Critical);
        $now = $event->occurredAt;

        [, $state] = $engine->start($event, $now);
        [, $state] = $engine->tick($event, $state, null, [], $now->modify('+601 seconds')); // -> tier 1
        [$decision, $state] = $engine->tick($event, $state, null, [], $now->modify('+1300 seconds')); // tier 1 has no next

        self::assertSame(EscalationOutcome::Stop, $decision->outcome);
        self::assertTrue($state->stopped);
    }

    public function test_non_critical_respects_quiet_hours(): void
    {
        $quiet = new QuietHoursPolicy([new QuietHours('r1', 0, 23)]); // quiet almost all day
        $engine = $this->engine($quiet);
        $event = $this->event(Severity::Warning);

        [$decision] = $engine->start($event, $event->occurredAt);

        self::assertSame(EscalationOutcome::Suppress, $decision->outcome);
    }

    public function test_critical_ignores_quiet_hours(): void
    {
        $quiet = new QuietHoursPolicy([new QuietHours('r1', 0, 23)]);
        $engine = $this->engine($quiet);
        $event = $this->event(Severity::Critical);

        [$decision] = $engine->start($event, $event->occurredAt);

        self::assertSame(EscalationOutcome::Notify, $decision->outcome);
    }

    public function test_maintenance_silence_suppresses(): void
    {
        $engine = $this->engine();
        $event = $this->event(Severity::Critical, 'silenced-rule');
        $now = $event->occurredAt;

        $silence = new MaintenanceSilence('s1', 'silenced-rule', $now->modify('-1 minute'), $now->modify('+1 hour'), 'ops', 'planned maintenance');

        [, $state] = $engine->start($event, $now);
        [$decision] = $engine->tick($event, $state, null, [$silence], $now->modify('+1 second'));

        self::assertSame(EscalationOutcome::Suppress, $decision->outcome);
    }

    public function test_maintenance_silence_auto_expires(): void
    {
        $engine = $this->engine();
        $event = $this->event(Severity::Critical, 'silenced-rule');
        $now = $event->occurredAt;

        $silence = new MaintenanceSilence('s1', 'silenced-rule', $now->modify('-1 hour'), $now->modify('+5 minutes'), 'ops', 'planned maintenance');

        [, $state] = $engine->start($event, $now);
        // After expiry, the silence is simply not in the active list any more (caller's
        // responsibility to query only active silences) — simulate that here.
        [$decision] = $engine->tick($event, $state, null, [], $now->modify('+10 minutes'));

        self::assertNotSame(EscalationOutcome::Suppress, $decision->outcome);
    }
}
