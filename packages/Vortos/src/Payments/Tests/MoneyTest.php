<?php

declare(strict_types=1);

namespace Vortos\Payments\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vortos\Payments\Exception\InvalidMoneyException;
use Vortos\Payments\ValueObject\Currency;
use Vortos\Payments\ValueObject\Money;

final class MoneyTest extends TestCase
{
    /**
     * The case the whole project exists for: an organiser publishes LKR 6,000
     * and the string sent to the rail — and covered by its signature — must be
     * exactly "6000.00".
     */
    public function testRendersTheLkrHeadlineAmountExactly(): void
    {
        self::assertSame('6000.00', Money::fromMinor(600_000, 'LKR')->toDecimalString());
    }

    #[DataProvider('decimalStrings')]
    public function testRendersEachIsoExponentCorrectly(int $minor, string $currency, string $expected): void
    {
        self::assertSame($expected, Money::fromMinor($minor, $currency)->toDecimalString());
    }

    /** @return iterable<string, array{int, string, string}> */
    public static function decimalStrings(): iterable
    {
        yield 'two decimals'          => [1_234, 'USD', '12.34'];
        yield 'sub-major amount'      => [5, 'USD', '0.05'];
        yield 'sub-major, leading'    => [50, 'USD', '0.50'];
        yield 'zero'                  => [0, 'USD', '0.00'];
        // JPY has no minor unit; rendering "1234.00" would ask a payer for a
        // hundred times the intended amount.
        yield 'zero-decimal currency' => [1_234, 'JPY', '1234'];
        yield 'three-decimal'         => [1_234, 'KWD', '1.234'];
        yield 'three-decimal, small'  => [4, 'BHD', '0.004'];
        yield 'large'                 => [123_456_789, 'LKR', '1234567.89'];
    }

    public function testRefusesNegativeAmounts(): void
    {
        $this->expectException(InvalidMoneyException::class);

        Money::fromMinor(-1, 'USD');
    }

    public function testRefusesToCombineDifferentCurrencies(): void
    {
        $this->expectException(InvalidMoneyException::class);

        Money::fromMinor(100, 'USD')->plus(Money::fromMinor(100, 'LKR'));
    }

    public function testSubtractionThatWouldGoNegativeIsRefused(): void
    {
        $this->expectException(InvalidMoneyException::class);

        Money::fromMinor(100, 'USD')->minus(Money::fromMinor(101, 'USD'));
    }

    public function testArithmeticStaysExactAcrossManyAdditions(): void
    {
        // 0.01 + 0.01 … a hundred times is 1.00 here and 0.9999999999999999 in
        // any implementation that reached for a float.
        $total = Money::zero('USD');
        for ($i = 0; $i < 100; $i++) {
            $total = $total->plus(Money::fromMinor(1, 'USD'));
        }

        self::assertSame(100, $total->minorUnits);
        self::assertSame('1.00', $total->toDecimalString());
    }

    /** Case and surrounding whitespace are normalised; anything else is refused. */
    public function testCurrencyCodesAreNormalisedAndValidated(): void
    {
        self::assertSame('LKR', Currency::of('lkr')->code);
        self::assertSame('LKR', Currency::of(' LKR ')->code);

        $this->expectException(InvalidMoneyException::class);
        Currency::of('144');
    }

    #[DataProvider('malformedCurrencyCodes')]
    public function testMalformedCurrencyCodesAreRefused(string $code): void
    {
        $this->expectException(InvalidMoneyException::class);

        Currency::of($code);
    }

    /** @return iterable<string, array{string}> */
    public static function malformedCurrencyCodes(): iterable
    {
        yield 'numeric ISO code' => ['144'];
        yield 'too short'        => ['LK'];
        yield 'too long'         => ['LKRR'];
        yield 'empty'            => [''];
        yield 'inner space'      => ['L KR'];
    }

    public function testEqualityComparesAmountAndCurrency(): void
    {
        self::assertTrue(Money::fromMinor(100, 'USD')->equals(Money::fromMinor(100, 'USD')));
        self::assertFalse(Money::fromMinor(100, 'USD')->equals(Money::fromMinor(100, 'LKR')));
    }
}
