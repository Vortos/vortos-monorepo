<?php

declare(strict_types=1);

namespace Vortos\Alerts\Dedupe;

use DateTimeImmutable;
use Vortos\Alerts\Event\AlertEvent;

/**
 * Pure fingerprint + window collapse (§3.3 stage 1) — a synthetic storm of N
 * identical alerts collapses to one outbound notification, with a throttled
 * "still firing" digest every `digestEvery` occurrences, never N pages.
 */
final class Dedupe
{
    public function __construct(
        private readonly int $digestEvery = 10,
    ) {}

    public function evaluate(AlertEvent $event, ?AlertState $previous, DedupeWindow $window, DateTimeImmutable $now): DedupeOutcome
    {
        $fingerprint = Fingerprint::of($event);

        if ($previous === null || $this->windowExpired($previous, $window, $now)) {
            return new DedupeOutcome(DedupeDecision::New, AlertState::firstSeen($fingerprint, $now));
        }

        $next = $previous->withOccurrence($now);

        if ($next->occurrenceCount % $this->digestEvery === 0) {
            return new DedupeOutcome(DedupeDecision::Digest, $next);
        }

        return new DedupeOutcome(DedupeDecision::Deduped, $next);
    }

    private function windowExpired(AlertState $previous, DedupeWindow $window, DateTimeImmutable $now): bool
    {
        return ($now->getTimestamp() - $previous->lastSeenAt->getTimestamp()) > $window->seconds;
    }
}
