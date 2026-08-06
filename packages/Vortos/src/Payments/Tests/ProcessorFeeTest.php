<?php

declare(strict_types=1);

namespace Vortos\Payments\Tests;

use PHPUnit\Framework\TestCase;
use Vortos\Payments\ValueObject\Money;
use Vortos\Payments\ValueObject\ProcessorFee;

final class ProcessorFeeTest extends TestCase
{
    public function testAKnownFeeReadsBack(): void
    {
        $fee = ProcessorFee::known(Money::fromMinor(147, 'USD'));

        self::assertTrue($fee->isKnown);
        self::assertSame(147, $fee->amountOrFail()->minorUnits);
    }

    /**
     * The whole point of the type: an unreported fee must be impossible to
     * mistake for a zero one. A reconciliation that subtracts zero reports a
     * perfect match it never made, and the drift alert stays green forever.
     */
    public function testAnUnknownFeeCannotBeReadAsZero(): void
    {
        $fee = ProcessorFee::unknown();

        self::assertFalse($fee->isKnown);
        self::assertNull($fee->amount);

        $this->expectException(\LogicException::class);
        $fee->amountOrFail();
    }

    public function testUnknownFeesAreNotScoredAsInconsistent(): void
    {
        // A rail that reports no fee cannot be caught out by its own
        // arithmetic; only rails that do report one are checked.
        $fee = ProcessorFee::unknown();

        self::assertSame(
            ['known' => false, 'minor_units' => null, 'currency' => null],
            $fee->toArray(),
        );
    }
}
