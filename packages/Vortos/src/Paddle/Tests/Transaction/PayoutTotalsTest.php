<?php

declare(strict_types=1);

namespace Vortos\Paddle\Tests\Transaction;

use Paddle\SDK\Entities\Transaction as SdkTransaction;
use PHPUnit\Framework\TestCase;
use Vortos\Paddle\Transaction\Transaction;

/**
 * Payout totals are the only place the API reports what Paddle kept, so the
 * mapper is pinned here — including the case where they are absent, which is
 * the one a reconciler is most likely to get wrong.
 */
final class PayoutTotalsTest extends TestCase
{
    public function test_a_billed_transaction_carries_what_paddle_kept(): void
    {
        $transaction = Transaction::fromSdk(SdkTransaction::from($this->payload([
            'subtotal'      => '6505',
            'discount'      => '0',
            'tax'           => '0',
            'total'         => '6505',
            'credit'        => '0',
            'balance'       => '0',
            'grand_total'   => '6505',
            'fee'           => '375',
            'earnings'      => '6130',
            'currency_code' => 'USD',
            'fee_rate'      => '0.05',
            'exchange_rate' => '1',
        ])));

        self::assertNotNull($transaction->payoutTotals);
        self::assertSame('375', $transaction->payoutTotals->fee);
        self::assertSame('6130', $transaction->payoutTotals->earnings);
        self::assertSame('USD', $transaction->payoutTotals->currencyCode);
        self::assertSame('0.05', $transaction->payoutTotals->feeRate);
    }

    public function test_minor_unit_helpers_return_integers(): void
    {
        $transaction = Transaction::fromSdk(SdkTransaction::from($this->payload([
            'subtotal' => '6505', 'discount' => '0', 'tax' => '0', 'total' => '6505',
            'credit' => '0', 'balance' => '0', 'grand_total' => '6505',
            'fee' => '375', 'earnings' => '6130', 'currency_code' => 'USD',
            'fee_rate' => '0.05', 'exchange_rate' => '1',
        ])));

        self::assertSame(375, $transaction->payoutTotals?->feeMinor());
        self::assertSame(6130, $transaction->payoutTotals?->earningsMinor());
    }

    /**
     * The contract that matters most. An unbilled transaction has no fee, and a
     * caller must be able to tell that apart from a zero fee — reading a missing
     * fee as zero makes a reconciler book the entire modelled amount as drift.
     */
    public function test_an_unbilled_transaction_reports_absent_rather_than_zero(): void
    {
        $transaction = Transaction::fromSdk(SdkTransaction::from($this->payload(null)));

        self::assertNull(
            $transaction->payoutTotals,
            'absent payout totals must stay absent, never be synthesised as zeroes',
        );
    }

    /**
     * The payout currency need not be the currency the buyer was charged in, so
     * it travels with the numbers rather than being assumed by the caller.
     */
    public function test_the_payout_currency_is_preserved_when_it_differs_from_the_charge(): void
    {
        $transaction = Transaction::fromSdk(SdkTransaction::from($this->payload([
            'subtotal' => '5000', 'discount' => '0', 'tax' => '0', 'total' => '5000',
            'credit' => '0', 'balance' => '0', 'grand_total' => '5000',
            'fee' => '260', 'earnings' => '4740', 'currency_code' => 'GBP',
            'fee_rate' => '0.05', 'exchange_rate' => '0.79',
        ], currencyCode: 'USD')));

        self::assertSame('USD', $transaction->currencyCode, 'the buyer was charged in USD');
        self::assertSame('GBP', $transaction->payoutTotals?->currencyCode, 'we are paid in GBP');
        self::assertSame('0.79', $transaction->payoutTotals?->exchangeRate);
    }

    /**
     * @param array<string, string>|null $payoutTotals
     * @return array<string, mixed>
     */
    private function payload(?array $payoutTotals, string $currencyCode = 'USD'): array
    {
        $details = [
            'tax_rates_used' => [],
            'totals' => [
                'subtotal' => '6505', 'discount' => '0', 'tax' => '0', 'total' => '6505',
                'credit' => '0', 'balance' => '0', 'grand_total' => '6505',
                'fee' => null, 'earnings' => null, 'currency_code' => $currencyCode,
                'credit_to_balance' => '0', 'grand_total_tax' => '0',
            ],
            'line_items' => [],
        ];

        if ($payoutTotals !== null) {
            $details['payout_totals'] = $payoutTotals + ['credit_to_balance' => '0', 'grand_total_tax' => '0'];
        }

        return [
            'id'              => 'txn_01',
            'status'          => 'completed',
            'customer_id'     => 'ctm_01',
            'address_id'      => null,
            'business_id'     => null,
            'custom_data'     => null,
            'currency_code'   => $currencyCode,
            'origin'          => 'web',
            'subscription_id' => null,
            'invoice_id'      => null,
            'invoice_number'  => null,
            'collection_mode' => 'automatic',
            'discount_id'     => null,
            'billing_details' => null,
            'billing_period'  => null,
            'items'           => [],
            'details'         => $details,
            'payments'        => [],
            'checkout'        => null,
            'created_at'      => '2026-08-05T00:00:00Z',
            'updated_at'      => '2026-08-05T00:00:00Z',
            'billed_at'       => $payoutTotals !== null ? '2026-08-05T00:00:00Z' : null,
        ];
    }
}
