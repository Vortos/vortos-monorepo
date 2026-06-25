<?php

declare(strict_types=1);

namespace Vortos\Alerts\Escalation;

use InvalidArgumentException;

/** Ordered tiers + per-tier wait (§3.5). An unacked critical re-pages the next tier after the wait. */
final readonly class EscalationPolicy
{
    /** @var array<int, EscalationTier> */
    private array $tiersByNumber;

    /** @param list<EscalationTier> $tiers */
    public function __construct(public array $tiers)
    {
        if ($tiers === []) {
            throw new InvalidArgumentException('EscalationPolicy requires at least one tier.');
        }

        $byNumber = [];
        foreach ($tiers as $tier) {
            if (isset($byNumber[$tier->tier])) {
                throw new InvalidArgumentException(sprintf('Duplicate escalation tier number %d.', $tier->tier));
            }
            $byNumber[$tier->tier] = $tier;
        }

        ksort($byNumber);
        $expected = 0;
        foreach (array_keys($byNumber) as $tierNumber) {
            if ($tierNumber !== $expected) {
                throw new InvalidArgumentException('EscalationPolicy tiers must be a contiguous sequence starting at 0.');
            }
            $expected++;
        }

        $this->tiersByNumber = $byNumber;
    }

    public function has(int $tierNumber): bool
    {
        return isset($this->tiersByNumber[$tierNumber]);
    }

    public function get(int $tierNumber): EscalationTier
    {
        return $this->tiersByNumber[$tierNumber] ?? throw new InvalidArgumentException(sprintf('Unknown escalation tier %d.', $tierNumber));
    }

    public function maxTier(): int
    {
        return max(array_keys($this->tiersByNumber));
    }
}
