<?php

declare(strict_types=1);

namespace Vortos\Payments\Tests;

use PHPUnit\Framework\TestCase;
use Vortos\Payments\Enum\CheckoutMode;
use Vortos\Payments\ValueObject\CheckoutInstruction;

final class CheckoutInstructionTest extends TestCase
{
    public function testOverlaySerialisesToItsOwnShape(): void
    {
        self::assertSame(
            ['mode' => 'overlay', 'reference' => 'txn_123'],
            CheckoutInstruction::overlay('txn_123')->toArray(),
        );
    }

    public function testRedirectSerialisesToItsOwnShape(): void
    {
        $instruction = CheckoutInstruction::redirect(
            'https://sandbox.payhere.lk/pay/checkout',
            ['merchant_id' => '1', 'amount' => '6000.00'],
        );

        self::assertSame(CheckoutMode::Redirect, $instruction->mode);
        self::assertSame(
            [
                'mode'       => 'redirect',
                'action_url' => 'https://sandbox.payhere.lk/pay/checkout',
                'fields'     => ['merchant_id' => '1', 'amount' => '6000.00'],
            ],
            $instruction->toArray(),
        );
    }

    /**
     * A plaintext or same-origin action URL would post signed payment fields
     * somewhere they must never go, and a payer cannot un-submit a form.
     */
    public function testRedirectRefusesANonHttpsAction(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CheckoutInstruction::redirect('http://sandbox.payhere.lk/pay/checkout', ['merchant_id' => '1']);
    }

    public function testRedirectRefusesEmptyFields(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CheckoutInstruction::redirect('https://sandbox.payhere.lk/pay/checkout', []);
    }
}
