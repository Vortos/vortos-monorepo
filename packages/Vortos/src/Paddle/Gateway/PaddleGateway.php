<?php

declare(strict_types=1);

namespace Vortos\Paddle\Gateway;

use Vortos\Paddle\Customer\Contract\ImmediateCustomerServiceInterface;
use Vortos\Paddle\Customer\Operation\CreateCustomerRequest;
use Vortos\Paddle\Exception\PaddleApiException;
use Vortos\Paddle\Exception\PaddleAuthException;
use Vortos\Paddle\Exception\PaddleCircuitOpenException;
use Vortos\Paddle\Exception\PaddleNotFoundException;
use Vortos\Paddle\Exception\PaddleRateLimitException;
use Vortos\Paddle\Exception\PaddleValidationException;
use Vortos\Paddle\Transaction\Contract\ImmediateAdjustmentServiceInterface;
use Vortos\Paddle\Transaction\Contract\ImmediateTransactionServiceInterface;
use Vortos\Paddle\Transaction\Operation\AdjustmentItemRequest;
use Vortos\Paddle\Transaction\Operation\CreateRefundRequest;
use Vortos\Paddle\Transaction\Operation\CreateTransactionRequest;
use Vortos\Paddle\Transaction\Operation\TransactionItemRequest;
use Vortos\Paddle\Transaction\Transaction;
use Vortos\Paddle\ValueObject\Money as PaddleMoney;
use Vortos\Paddle\ValueObject\PaddleTransactionId;
use Vortos\Paddle\ValueObject\TransactionStatus as PaddleTransactionStatus;
use Vortos\Payments\Contract\GatewayInterface;
use Vortos\Payments\Enum\CheckoutMode;
use Vortos\Payments\Enum\TransactionStatus;
use Vortos\Payments\Exception\ChargeRejectedException;
use Vortos\Payments\Exception\CurrencyNotSupportedException;
use Vortos\Payments\Exception\GatewayUnavailableException;
use Vortos\Payments\Exception\RefundNotSupportedException;
use Vortos\Payments\Exception\TransactionNotFoundException;
use Vortos\Payments\ValueObject\ChargeRequest;
use Vortos\Payments\ValueObject\ChargeResult;
use Vortos\Payments\ValueObject\CheckoutInstruction;
use Vortos\Payments\ValueObject\GatewayTransaction;
use Vortos\Payments\ValueObject\Money;
use Vortos\Payments\ValueObject\PayoutTotals;
use Vortos\Payments\ValueObject\ProcessorFee;
use Vortos\Payments\ValueObject\RailCapabilities;
use Vortos\Payments\ValueObject\RefundRequest;
use Vortos\Payments\ValueObject\RefundResult;

/**
 * Paddle as one interchangeable payment rail.
 *
 * ── What this is and is not ───────────────────────────────────────────────
 * An adapter, not a rewrite. Every call below delegates to the services this
 * package already exposes — they keep their own public surface, their circuit
 * breaker, their idempotency store and their exception types, and nothing that
 * uses them directly changes. What is new is that a caller who does *not* want
 * to know it is talking to Paddle now has a way not to.
 *
 * ── The one rule this adapter must never break ────────────────────────────
 * It does not convert currencies. Paddle cannot bill LKR; asked to, this
 * throws. Converting here would apply a rate nobody quoted and nobody
 * disclosed, and the organiser would be credited less than the fee they
 * published — which is the defect the whole multi-rail design exists to
 * remove. Conversion is a priced, snapshotted decision made upstream, and by
 * the time a charge reaches a gateway it is already denominated in a currency
 * that gateway can bill.
 */
final class PaddleGateway implements GatewayInterface
{
    public const ID = 'paddle';

    public function __construct(
        private readonly ImmediateCustomerServiceInterface   $customers,
        private readonly ImmediateTransactionServiceInterface $transactions,
        private readonly ImmediateAdjustmentServiceInterface  $adjustments,
        /**
         * The Paddle product every ad-hoc line hangs off.
         *
         * Paddle has no truly product-less line: a non-catalog price still
         * belongs to a product, which is what its reporting groups by. One
         * product for all ad-hoc charges keeps the price catalog from filling
         * with a row per registration.
         */
        private readonly string $defaultProductId,
    ) {}

    public function id(): string
    {
        return self::ID;
    }

    public function capabilities(): RailCapabilities
    {
        return new RailCapabilities(
            // Paddle sells as principal: it is the seller on the payer's
            // statement, it computes and files their sales tax, and a
            // chargeback lands on Paddle rather than on us. Everything about
            // our tax position on this rail follows from these three.
            isMerchantOfRecord:         true,
            remitsTax:                  true,
            handlesChargebacks:         true,
            // Only after billing, and only in the payout currency — but it is
            // reported, which is what lets the modelled processing fee be
            // reconciled against reality.
            reportsPerTransactionFee:   true,
            supportsRefunds:            true,
            supportedCurrencies:        PaddleChargeableCurrency::values(),
            settlementCurrency:         PaddleChargeableCurrency::FALLBACK->value,
            conversionFallbackCurrency: PaddleChargeableCurrency::FALLBACK->value,
            checkoutMode:               CheckoutMode::Overlay,
        );
    }

    public function createCharge(ChargeRequest $request): ChargeResult
    {
        $currency = $request->currency()->code;

        if (!$this->capabilities()->supports($currency)) {
            throw CurrencyNotSupportedException::forRail(self::ID, $currency, $this->capabilities());
        }

        try {
            // Paddle has no lookup by email, so this is create-and-recover: a
            // returning payer entering a second tournament is the ordinary
            // case, not an error.
            $customerId = $this->customers->findOrCreate(new CreateCustomerRequest(
                email: $request->payer->email,
                name:  $request->payer->fullName() !== '' ? $request->payer->fullName() : null,
            ));

            $items = [];
            foreach ($request->lines as $line) {
                $items[] = TransactionItemRequest::nonCatalog(
                    productId:   $this->defaultProductId,
                    unitPrice:   new PaddleMoney($line->unitPrice->minorUnits, $currency),
                    quantity:    $line->quantity,
                    description: $line->description,
                );
            }

            $transactionId = $this->transactions->create(new CreateTransactionRequest(
                customerId:   $customerId,
                items:        $items,
                currencyCode: $currency,
                // Our reference travels in custom_data because that is what
                // Paddle echoes on the webhook. Without it a settlement cannot
                // find the payment it settles.
                customData:   ['reference' => $request->reference] + $request->metadata,
            ));
        } catch (\Throwable $e) {
            throw $this->translate($e);
        }

        return new ChargeResult(
            reference:        $request->reference,
            gatewayReference: $transactionId->value,
            total:            $request->total,
            checkout:         CheckoutInstruction::overlay($transactionId->value),
        );
    }

    public function fetchTransaction(string $gatewayReference): GatewayTransaction
    {
        try {
            $transaction = $this->transactions->get(PaddleTransactionId::of($gatewayReference));
        } catch (\Throwable $e) {
            throw $this->translate($e, $gatewayReference);
        }

        $status = $this->mapStatus($transaction->status);

        return new GatewayTransaction(
            reference:        $this->referenceFrom($transaction),
            gatewayReference: $transaction->id->value,
            status:           $status,
            total:            Money::fromMinor((int) $transaction->total, $transaction->currencyCode),
            payout:           $this->mapPayout($transaction),
            // Paddle stamps billedAt when it bills. On the rare transaction
            // that is settled without one, updatedAt is still Paddle's own
            // timestamp — imprecise, but real provenance rather than our clock
            // standing in for theirs.
            settledAt:        $status->isSettled()
                ? ($transaction->billedAt ?? $transaction->updatedAt)
                : null,
        );
    }

    public function refund(RefundRequest $request): RefundResult
    {
        try {
            $transaction = $this->transactions->get(PaddleTransactionId::of($request->gatewayReference));
        } catch (\Throwable $e) {
            throw $this->translate($e, $request->gatewayReference);
        }

        $currency  = $transaction->currencyCode;
        $captured  = 0;
        foreach ($transaction->lineItems as $lineItem) {
            $captured += (int) $lineItem->total;
        }

        $requested = $request->isFullRefund()
            ? $captured
            : $request->amount->minorUnits;

        if ($request->amount !== null && $request->amount->currency->code !== $currency) {
            throw new RefundNotSupportedException(sprintf(
                'Refusing to refund %s against a transaction billed in %s; a cross-currency refund is a conversion nobody priced.',
                $request->amount->currency->code,
                $currency,
            ));
        }

        // Refused, never clamped. A caller asking for more than was captured
        // has lost track of what it captured, and quietly refunding the smaller
        // amount hides that from the reconciliation that would have caught it.
        if ($requested > $captured) {
            throw RefundNotSupportedException::exceedsCaptured(self::ID, $requested, $captured, $currency);
        }

        try {
            $adjustmentId = $this->adjustments->createRefund(new CreateRefundRequest(
                transactionId: $transaction->id,
                reason:        $request->reason,
                items:         $this->allocate($requested, $transaction),
            ));
        } catch (\Throwable $e) {
            throw $this->translate($e, $request->gatewayReference);
        }

        return new RefundResult(
            gatewayRefundReference: $adjustmentId->value,
            amount:                 Money::fromMinor($requested, $currency),
            // Paddle approves refunds asynchronously; the money is not back on
            // the card when this returns, and saying otherwise would have the
            // ledger book a completion that has not happened.
            isImmediate:            false,
        );
    }

    /**
     * Spreads a refund across the transaction's lines, largest first.
     *
     * Paddle refunds per line item, so an amount has to be allocated. Largest
     * first keeps a partial refund on the entry-fee line rather than
     * fragmenting it across the fee line, which is what a human reading the
     * transaction later expects to see. The final line takes the remainder, so
     * the allocated parts always sum to exactly the requested amount — the same
     * remainder-on-one-line rule the split-payment ledger uses.
     *
     * @return list<AdjustmentItemRequest>
     */
    private function allocate(int $requestedMinor, Transaction $transaction): array
    {
        $lines = $transaction->lineItems;
        usort($lines, static fn ($a, $b): int => (int) $b->total <=> (int) $a->total);

        $items     = [];
        $remaining = $requestedMinor;

        foreach ($lines as $line) {
            if ($remaining <= 0) {
                break;
            }

            $take      = min($remaining, (int) $line->total);
            $remaining -= $take;

            if ($take > 0) {
                $items[] = new AdjustmentItemRequest(
                    lineItemId: $line->id,
                    amount:     (string) $take,
                );
            }
        }

        if ($items === []) {
            throw new RefundNotSupportedException(
                'This transaction has no refundable line items; refund it in the Paddle dashboard and record it.'
            );
        }

        return $items;
    }

    private function mapStatus(PaddleTransactionStatus $status): TransactionStatus
    {
        return match ($status) {
            // `billed` is an issued invoice, not a payment: the money has not
            // moved. Reading it as settled would credit a ledger for a wire
            // transfer nobody has sent yet.
            PaddleTransactionStatus::Draft,
            PaddleTransactionStatus::Ready,
            PaddleTransactionStatus::Billed,
            PaddleTransactionStatus::PastDue  => TransactionStatus::Pending,
            PaddleTransactionStatus::Paid,
            PaddleTransactionStatus::Completed => TransactionStatus::Completed,
            PaddleTransactionStatus::Canceled  => TransactionStatus::Cancelled,
        };
    }

    private function mapPayout(Transaction $transaction): ?PayoutTotals
    {
        $totals = $transaction->payoutTotals;

        // Absent stays absent. Synthesising zeroes here would let a reconciler
        // read "not billed yet" as "billed, fee was zero" and book the entire
        // modelled fee as drift.
        if ($totals === null) {
            return null;
        }

        return new PayoutTotals(
            gross:        Money::fromMinor((int) $totals->grandTotal, $totals->currencyCode),
            fee:          ProcessorFee::known(Money::fromMinor($totals->feeMinor(), $totals->currencyCode)),
            earnings:     Money::fromMinor($totals->earningsMinor(), $totals->currencyCode),
            exchangeRate: $totals->exchangeRate !== '' ? $totals->exchangeRate : null,
        );
    }

    private function referenceFrom(Transaction $transaction): ?string
    {
        $reference = $transaction->customData['reference'] ?? null;

        return is_string($reference) && $reference !== '' ? $reference : null;
    }

    /**
     * Maps this package's exceptions onto the rail-agnostic ones.
     *
     * The distinction that matters is retryable versus terminal. A caller that
     * cannot tell an open circuit from a declined card either tells a payer to
     * try again forever, or gives up on a payment that would have gone through
     * a second later.
     */
    private function translate(\Throwable $e, ?string $reference = null): \Throwable
    {
        return match (true) {
            $e instanceof PaddleNotFoundException && $reference !== null
                => TransactionNotFoundException::for(self::ID, $reference),

            // Transient by nature: the request never reached a decision, so the
            // outcome is unknown and the caller must reconcile before retrying.
            $e instanceof PaddleCircuitOpenException,
            $e instanceof PaddleRateLimitException
                => GatewayUnavailableException::for(self::ID, $e->getMessage(), $e),

            // Our credentials, not the payer's problem — but equally not
            // something a retry fixes.
            $e instanceof PaddleAuthException
                => GatewayUnavailableException::for(self::ID, 'authentication rejected', $e),

            $e instanceof PaddleValidationException
                => new ChargeRejectedException($e->getMessage(), self::ID, $e->errorCode, $e),

            $e instanceof PaddleApiException
                => new ChargeRejectedException($e->getMessage(), self::ID, $e->errorCode, $e),

            // Anything else is genuinely unexpected and must not be dressed up
            // as a payment outcome.
            default => $e,
        };
    }
}
