<?php

declare(strict_types=1);

namespace Vortos\Payments\Tests;

use PHPUnit\Framework\TestCase;
use Vortos\Payments\Exception\InvalidMoneyException;
use Vortos\Payments\ValueObject\ChargeLine;
use Vortos\Payments\ValueObject\ChargeRequest;
use Vortos\Payments\ValueObject\Money;
use Vortos\Payments\ValueObject\PayerDetails;

final class ChargeRequestTest extends TestCase
{
    public function testLinesMustSumToTheTotal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/reconcile exactly/');

        new ChargeRequest(
            reference: 'reg-1',
            total:     Money::fromMinor(600_000, 'LKR'),
            lines:     [new ChargeLine('Tournament registration', Money::fromMinor(599_999, 'LKR'))],
            payer:     $this->payer(),
        );
    }

    public function testAReconcilingRequestIsAccepted(): void
    {
        $request = new ChargeRequest(
            reference: 'reg-1',
            total:     Money::fromMinor(600_000, 'LKR'),
            lines:     [
                new ChargeLine('Tournament registration', Money::fromMinor(560_000, 'LKR')),
                new ChargeLine('Processing & platform fee', Money::fromMinor(40_000, 'LKR')),
            ],
            payer:     $this->payer(),
        );

        self::assertSame(600_000, $request->total->minorUnits);
        self::assertSame('LKR', $request->currency()->code);
    }

    public function testQuantityIsMultipliedIntoTheLineTotal(): void
    {
        $request = new ChargeRequest(
            reference: 'reg-1',
            total:     Money::fromMinor(1_800_000, 'LKR'),
            lines:     [new ChargeLine('Tournament registration', Money::fromMinor(600_000, 'LKR'), quantity: 3)],
            payer:     $this->payer(),
        );

        self::assertSame(1_800_000, $request->total->minorUnits);
    }

    /**
     * A fee line left in the organiser's currency while the base was converted
     * is a real and easy mistake. It must surface as a currency mismatch, not
     * as a total that happens to be wrong.
     */
    public function testAMixedCurrencyLineIsRefused(): void
    {
        $this->expectException(InvalidMoneyException::class);

        new ChargeRequest(
            reference: 'reg-1',
            total:     Money::fromMinor(2_000, 'USD'),
            lines:     [
                new ChargeLine('Tournament registration', Money::fromMinor(1_800, 'USD')),
                new ChargeLine('Processing & platform fee', Money::fromMinor(200, 'LKR')),
            ],
            payer:     $this->payer(),
        );
    }

    public function testAZeroChargeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ChargeRequest(
            reference: 'reg-1',
            total:     Money::zero('USD'),
            lines:     [new ChargeLine('Tournament registration', Money::zero('USD'))],
            payer:     $this->payer(),
        );
    }

    public function testAReferenceIsRequired(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ChargeRequest(
            reference: '  ',
            total:     Money::fromMinor(100, 'USD'),
            lines:     [new ChargeLine('Tournament registration', Money::fromMinor(100, 'USD'))],
            payer:     $this->payer(),
        );
    }

    private function payer(): PayerDetails
    {
        return new PayerDetails(
            email:     'payer@example.com',
            firstName: 'Nimal',
            lastName:  'Perera',
        );
    }
}
