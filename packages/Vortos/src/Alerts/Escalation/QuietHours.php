<?php

declare(strict_types=1);

namespace Vortos\Alerts\Escalation;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * A per-responder quiet window (§3.5). Non-critical alerts respect quiet hours
 * (queued/digested); critical always pages — enforced by the caller
 * ({@see EscalationEngine}), never by this VO.
 */
final readonly class QuietHours
{
    public function __construct(
        public string $responderId,
        public int $startHour,
        public int $endHour,
        public string $timezone = 'UTC',
    ) {
        if ($responderId === '') {
            throw new InvalidArgumentException('QuietHours responderId must not be empty.');
        }
        if ($startHour < 0 || $startHour > 23 || $endHour < 0 || $endHour > 23) {
            throw new InvalidArgumentException('QuietHours startHour/endHour must be in [0, 23].');
        }
    }

    public function isQuietAt(DateTimeImmutable $now): bool
    {
        $local = $now->setTimezone(new DateTimeZone($this->timezone));
        $hour = (int) $local->format('G');

        if ($this->startHour === $this->endHour) {
            return false; // zero-width window = never quiet
        }

        if ($this->startHour < $this->endHour) {
            return $hour >= $this->startHour && $hour < $this->endHour;
        }

        // Wraps midnight, e.g. 22 -> 7.
        return $hour >= $this->startHour || $hour < $this->endHour;
    }
}
