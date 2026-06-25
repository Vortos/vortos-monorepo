<?php

declare(strict_types=1);

namespace Vortos\Alerts\Escalation;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * An ordered, time-window-based on-call schedule (§3.5). Pluggable so PagerDuty/
 * Opsgenie can own rotation when installed (§8 "or delegate rotation") — this class
 * is the in-core default, never the only implementation.
 */
final readonly class OnCallRotation
{
    /** @param list<Responder> $responders */
    public function __construct(
        public array $responders,
        public DateTimeImmutable $epoch,
        public int $periodSeconds = 604800,
    ) {
        if ($responders === []) {
            throw new InvalidArgumentException('OnCallRotation requires at least one responder.');
        }
        if ($periodSeconds < 1) {
            throw new InvalidArgumentException('OnCallRotation periodSeconds must be >= 1.');
        }
    }

    public function currentResponder(DateTimeImmutable $now): Responder
    {
        $elapsed = max(0, $now->getTimestamp() - $this->epoch->getTimestamp());
        $index = intdiv($elapsed, $this->periodSeconds) % count($this->responders);

        return $this->responders[$index];
    }

    /** The Nth-next responder after the current one, for escalation tiers beyond the primary. Null past the roster's end. */
    public function responderAtOffset(DateTimeImmutable $now, int $offset): ?Responder
    {
        if ($offset < 0) {
            throw new InvalidArgumentException('offset must be >= 0.');
        }
        if ($offset >= count($this->responders)) {
            return null;
        }

        $elapsed = max(0, $now->getTimestamp() - $this->epoch->getTimestamp());
        $baseIndex = intdiv($elapsed, $this->periodSeconds) % count($this->responders);
        $index = ($baseIndex + $offset) % count($this->responders);

        return $this->responders[$index];
    }
}
