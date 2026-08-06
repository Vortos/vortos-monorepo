<?php

declare(strict_types=1);

namespace Vortos\Payments\ValueObject;

/**
 * One line the payer sees on the rail's payment page.
 *
 * Lines exist for the payer, not for accounting: what the entry fee is, and
 * what was added on top. The internal split between platform and processing
 * fee is deliberately *not* expressed here — it is one merged line, because
 * the processing half is modelled rather than measured and naming a processor's
 * cut to a payer invites a dispute that cannot be settled from our side.
 */
final readonly class ChargeLine
{
    public function __construct(
        public string $description,
        public Money  $unitPrice,
        public int    $quantity = 1,
    ) {
        if (trim($description) === '') {
            throw new \InvalidArgumentException('A charge line needs a description; the payer reads it.');
        }

        if ($quantity < 1) {
            throw new \InvalidArgumentException('A charge line needs a quantity of at least one.');
        }
    }

    public function total(): Money
    {
        return $this->unitPrice->multipliedBy($this->quantity);
    }
}
