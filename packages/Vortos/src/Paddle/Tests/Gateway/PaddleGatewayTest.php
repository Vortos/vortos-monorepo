<?php

declare(strict_types=1);

namespace Vortos\Paddle\Tests\Gateway;

use PHPUnit\Framework\TestCase;
use Vortos\Paddle\Gateway\PaddleGateway;
use Vortos\Paddle\Tests\Gateway\Fake\FakeAdjustmentService;
use Vortos\Paddle\Tests\Gateway\Fake\FakeCustomerService;
use Vortos\Paddle\Tests\Gateway\Fake\FakeTransactionService;
use Vortos\Payments\Enum\TransactionStatus;
use Vortos\Payments\Exception\RefundNotSupportedException;
use Vortos\Payments\ValueObject\ChargeLine;
use Vortos\Payments\ValueObject\ChargeRequest;
use Vortos\Payments\ValueObject\Money;
use Vortos\Payments\ValueObject\PayerDetails;
use Vortos\Payments\ValueObject\RefundRequest;

/**
 * Adapter behaviour the shared conformance suite cannot express, because it is
 * about what Paddle specifically is sent and how Paddle specifically answers.
 */
final class PaddleGatewayTest extends TestCase
{
    private FakeCustomerService    $customers;
    private FakeTransactionService $transactions;
    private FakeAdjustmentService  $adjustments;
    private PaddleGateway          $gateway;

    protected function setUp(): void
    {
        $this->customers    = new FakeCustomerService();
        $this->transactions = new FakeTransactionService();
        $this->adjustments  = new FakeAdjustmentService();

        $this->gateway = new PaddleGateway(
            customers:        $this->customers,
            transactions:     $this->transactions,
            adjustments:      $this->adjustments,
            defaultProductId: 'pro_test',
        );
    }

    public function testEachLineIsSentAtItsExactMinorUnitAmount(): void
    {
        $this->gateway->createCharge($this->charge());

        $items = $this->transactions->created[0]->items;

        self::assertCount(2, $items);
        self::assertSame(1_800, $items[0]->unitPrice?->amount);
        self::assertSame(200, $items[1]->unitPrice?->amount);
        self::assertSame('USD', $items[0]->unitPrice?->currencyCode);
        self::assertSame('Tournament registration', $items[0]->description);
        self::assertSame('Processing & platform fee', $items[1]->description);
    }

    /**
     * Without this, a settlement webhook cannot find the payment it settles —
     * which is a payer charged, a ledger uncredited, and a support ticket.
     */
    public function testOurReferenceTravelsInCustomDataForTheWebhook(): void
    {
        $this->gateway->createCharge($this->charge());

        self::assertSame('reg-1', $this->transactions->created[0]->customData['reference'] ?? null);
    }

    public function testCallerMetadataIsCarriedAlongsideTheReference(): void
    {
        $this->gateway->createCharge(new ChargeRequest(
            reference: 'reg-1',
            total:     Money::fromMinor(2_000, 'USD'),
            lines:     [new ChargeLine('Tournament registration', Money::fromMinor(2_000, 'USD'))],
            payer:     new PayerDetails('payer@example.com'),
            metadata:  ['entry_id' => 'ent-9'],
        ));

        $customData = $this->transactions->created[0]->customData ?? [];

        self::assertSame('reg-1', $customData['reference']);
        self::assertSame('ent-9', $customData['entry_id']);
    }

    /**
     * A caller must never be able to overwrite the reference through metadata:
     * the webhook resolves the payment by it, so a collision would settle the
     * wrong registration.
     */
    public function testMetadataCannotOverwriteTheReference(): void
    {
        $this->gateway->createCharge(new ChargeRequest(
            reference: 'reg-1',
            total:     Money::fromMinor(2_000, 'USD'),
            lines:     [new ChargeLine('Tournament registration', Money::fromMinor(2_000, 'USD'))],
            payer:     new PayerDetails('payer@example.com'),
            metadata:  ['reference' => 'reg-attacker'],
        ));

        self::assertSame('reg-1', $this->transactions->created[0]->customData['reference'] ?? null);
    }

    public function testASettledTransactionReportsItsFeeAndSettlementTime(): void
    {
        $transaction = $this->gateway->fetchTransaction('txn_settled');

        self::assertSame(TransactionStatus::Completed, $transaction->status);
        self::assertTrue($transaction->isSettled());
        self::assertNotNull($transaction->settledAt);
        self::assertSame('reg-conformance-1', $transaction->reference);

        self::assertNotNull($transaction->payout);
        self::assertTrue($transaction->payout->fee->isKnown);
        self::assertSame(150, $transaction->payout->fee->amountOrFail()->minorUnits);
        self::assertSame(1_850, $transaction->payout->earnings->minorUnits);
        self::assertTrue($transaction->payout->isSelfConsistent());
    }

    public function testAPartialRefundIsAllocatedLargestLineFirst(): void
    {
        $this->gateway->refund(new RefundRequest(
            gatewayReference: 'txn_settled',
            amount:           Money::fromMinor(1_000, 'USD'),
            reason:           'withdrew before the draw',
            idempotencyKey:   'refund-1',
        ));

        $items = $this->adjustments->refunds[0]->items;

        // The whole 1000 fits on the 1800 entry-fee line, so the fee line is
        // left alone rather than the refund being fragmented across both.
        self::assertCount(1, $items);
        self::assertSame('txnitm_1', $items[0]->lineItemId);
        self::assertSame('1000', $items[0]->amount);
    }

    public function testAFullRefundCoversEveryLineExactly(): void
    {
        $result = $this->gateway->refund(new RefundRequest(
            gatewayReference: 'txn_settled',
            amount:           null,
            reason:           'event cancelled',
            idempotencyKey:   'refund-2',
        ));

        $items = $this->adjustments->refunds[0]->items;
        $total = array_sum(array_map(static fn ($i): int => (int) $i->amount, $items));

        self::assertSame(2_000, $total);
        self::assertSame(2_000, $result->amount->minorUnits);
        // Paddle approves refunds asynchronously; claiming otherwise would have
        // a ledger book a completion that has not happened.
        self::assertFalse($result->isImmediate);
    }

    public function testACrossCurrencyRefundIsRefused(): void
    {
        $this->expectException(RefundNotSupportedException::class);

        $this->gateway->refund(new RefundRequest(
            gatewayReference: 'txn_settled',
            amount:           Money::fromMinor(1_000, 'LKR'),
            reason:           'wrong currency',
            idempotencyKey:   'refund-3',
        ));
    }

    private function charge(): ChargeRequest
    {
        return new ChargeRequest(
            reference: 'reg-1',
            total:     Money::fromMinor(2_000, 'USD'),
            lines:     [
                new ChargeLine('Tournament registration', Money::fromMinor(1_800, 'USD')),
                new ChargeLine('Processing & platform fee', Money::fromMinor(200, 'USD')),
            ],
            payer:     new PayerDetails('payer@example.com', 'Nimal', 'Perera'),
        );
    }
}
