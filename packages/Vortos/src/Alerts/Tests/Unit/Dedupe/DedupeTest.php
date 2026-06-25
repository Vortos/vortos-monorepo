<?php

declare(strict_types=1);

namespace Vortos\Alerts\Tests\Unit\Dedupe;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Vortos\Alerts\Dedupe\Dedupe;
use Vortos\Alerts\Dedupe\DedupeDecision;
use Vortos\Alerts\Dedupe\DedupeWindow;
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
        $dedupe = new Dedupe(digestEvery: 1000); // disable digest noise for this assertion
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

    public function test_digest_fires_every_n_occurrences(): void
    {
        $dedupe = new Dedupe(digestEvery: 10);
        $window = new DedupeWindow(300);
        $now = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        $state = null;
        $digestCount = 0;

        for ($i = 0; $i < 30; $i++) {
            $at = $now->modify("+{$i} seconds");
            $outcome = $dedupe->evaluate($this->event($at), $state, $window, $at);
            $state = $outcome->nextState;
            if ($outcome->decision === DedupeDecision::Digest) {
                $digestCount++;
            }
        }

        self::assertSame(3, $digestCount); // occurrences 10, 20, 30
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
