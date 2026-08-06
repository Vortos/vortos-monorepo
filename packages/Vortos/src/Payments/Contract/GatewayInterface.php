<?php

declare(strict_types=1);

namespace Vortos\Payments\Contract;

use Vortos\Payments\Exception\ChargeRejectedException;
use Vortos\Payments\Exception\CurrencyNotSupportedException;
use Vortos\Payments\Exception\GatewayUnavailableException;
use Vortos\Payments\Exception\RefundNotSupportedException;
use Vortos\Payments\Exception\TransactionNotFoundException;
use Vortos\Payments\ValueObject\ChargeRequest;
use Vortos\Payments\ValueObject\ChargeResult;
use Vortos\Payments\ValueObject\GatewayTransaction;
use Vortos\Payments\ValueObject\RailCapabilities;
use Vortos\Payments\ValueObject\RefundRequest;
use Vortos\Payments\ValueObject\RefundResult;

/**
 * One payment rail.
 *
 * ── What an implementation may not do ─────────────────────────────────────
 * **Never convert a currency.** If `capabilities()` does not list the charge
 * currency, throw. An adapter that quietly converts applies a rate nobody
 * quoted, nobody snapshotted and nobody disclosed, and the organisation is
 * credited less than the fee it published — which is the exact defect this
 * whole abstraction was built to eliminate.
 *
 * **Never round.** Amounts arrive as integer minor units and are transmitted
 * as integer minor units, or as a decimal string derived from them by
 * `Money::toDecimalString()`. A rail whose API takes a float is a rail whose
 * adapter formats a string.
 *
 * **Never report settlement from a browser.** `createCharge()` returns an
 * intention. Only a verified webhook, or `fetchTransaction()`, may say money
 * moved.
 *
 * Implementations are expected to be stateless and safe to hold as a singleton
 * across a long-lived worker: no memoised per-request state on properties.
 */
interface GatewayInterface
{
    /**
     * Stable machine identifier — `paddle`, `payhere`.
     *
     * Lower-case, no spaces. It is persisted on ledger entries and payment
     * snapshots, so changing it later rewrites history's meaning; treat it as
     * permanent from the first settled payment.
     */
    public function id(): string;

    /**
     * What this rail is and what it can do.
     *
     * Must be cheap and side-effect free — routing calls it on every checkout,
     * before anything else happens.
     */
    public function capabilities(): RailCapabilities;

    /**
     * Opens a checkout. Does not take money.
     *
     * @throws CurrencyNotSupportedException when the charge currency is not billable by this rail.
     * @throws ChargeRejectedException       when the rail understood and refused.
     * @throws GatewayUnavailableException   when the rail could not be reached; the outcome is UNKNOWN,
     *                                       so reconcile by reference before charging again.
     */
    public function createCharge(ChargeRequest $request): ChargeResult;

    /**
     * The rail's current, authoritative view of a charge.
     *
     * @throws TransactionNotFoundException
     * @throws GatewayUnavailableException
     */
    public function fetchTransaction(string $gatewayReference): GatewayTransaction;

    /**
     * @throws RefundNotSupportedException when this rail cannot refund programmatically,
     *                                     or when the amount exceeds what was captured.
     * @throws TransactionNotFoundException
     * @throws GatewayUnavailableException
     */
    public function refund(RefundRequest $request): RefundResult;
}
