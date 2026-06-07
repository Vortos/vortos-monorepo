<?php

declare(strict_types=1);

namespace Vortos\Paddle\Tests\ValueObject;

use PHPUnit\Framework\TestCase;
use Vortos\Paddle\ValueObject\Money;

final class MoneyTest extends TestCase
{
    public function test_creates_valid_money(): void
    {
        $money = new Money(1000, 'USD');
        $this->assertSame(1000, $money->amount);
        $this->assertSame('USD', $money->currencyCode);
    }

    public function test_zero_amount_is_valid(): void
    {
        $money = new Money(0, 'EUR');
        $this->assertSame(0, $money->amount);
    }

    public function test_negative_amount_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be negative');
        new Money(-1, 'USD');
    }

    public function test_currency_code_must_be_three_characters(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('3 characters');
        new Money(100, 'US');
    }

    public function test_currency_code_too_long_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Money(100, 'USDA');
    }

    public function test_to_amount_string(): void
    {
        $money = new Money(1099, 'GBP');
        $this->assertSame('1099', $money->toAmountString());
    }

    public function test_equals_same_amount_and_currency(): void
    {
        $a = new Money(500, 'USD');
        $b = new Money(500, 'USD');
        $this->assertTrue($a->equals($b));
    }

    public function test_equals_is_case_insensitive_for_currency(): void
    {
        $a = new Money(500, 'usd');
        $b = new Money(500, 'USD');
        $this->assertTrue($a->equals($b));
    }

    public function test_not_equal_different_amount(): void
    {
        $a = new Money(500, 'USD');
        $b = new Money(600, 'USD');
        $this->assertFalse($a->equals($b));
    }

    public function test_not_equal_different_currency(): void
    {
        $a = new Money(500, 'USD');
        $b = new Money(500, 'EUR');
        $this->assertFalse($a->equals($b));
    }
}
