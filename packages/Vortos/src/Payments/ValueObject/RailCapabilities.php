<?php

declare(strict_types=1);

namespace Vortos\Payments\ValueObject;

use Vortos\Payments\Enum\CheckoutMode;

/**
 * What a payment rail actually is, beyond "it takes cards".
 *
 * ── Why this type exists at all ───────────────────────────────────────────
 * Paddle is a merchant of record: it is the seller of record on the payer's
 * statement, it charges and remits the payer's sales tax, and it absorbs
 * chargebacks. PayHere is a payment gateway: the money is collected in *our*
 * name, so the tax registration, the filing and the chargeback liability are
 * ours.
 *
 * Those are the same transaction to a `createCharge()` call and completely
 * different transactions to a tax authority. If GatewayInterface let one be
 * swapped for the other without the caller being able to see the difference,
 * the day we route a currency to a gateway rail is the day we silently
 * acquire a tax obligation nobody wrote down. Every field below exists so that
 * a decision which depends on the distinction has something to read.
 *
 * ── This replaces the global "chargeable currency" list ───────────────────
 * Which currencies can be billed is a property of the rail, not of the
 * platform. Holding one global list forces every rail to the intersection of
 * all of them — which is precisely the bug that made an organiser publishing
 * LKR get paid in converted USD, short by the FX spread, when a rail that
 * bills LKR natively existed all along.
 */
final readonly class RailCapabilities
{
    /**
     * @param list<string> $supportedCurrencies ISO 4217 codes this rail can bill directly.
     */
    public function __construct(
        /** Seller of record on the payer's statement. */
        public bool $isMerchantOfRecord,

        /** The rail calculates, collects and files the payer's sales tax. When false, we do. */
        public bool $remitsTax,

        /** The rail absorbs chargeback liability. When false, we do. */
        public bool $handlesChargebacks,

        /**
         * The rail reports its own fee per transaction, programmatically.
         *
         * When false, the modelled processing fee cannot be reconciled against
         * anything and reconciliation must record that it *could not* check —
         * never a zero difference, which reads identically to a verified match
         * on every report and every alert.
         */
        public bool $reportsPerTransactionFee,

        /** Refunds can be issued through the API rather than a merchant portal. */
        public bool $supportsRefunds,

        /** @var list<string> */
        public array $supportedCurrencies,

        /**
         * What the rail pays us in. May differ from what the payer was billed
         * — that difference is the rail's own FX, and it is why a ledger
         * credits what was received rather than what was priced.
         */
        public string $settlementCurrency,

        /**
         * Where a charge in an *unsupported* currency goes, if anywhere.
         *
         * Paddle sets USD: it cannot bill LKR, so an LKR price is converted by
         * the caller and billed in USD. PayHere sets null: it bills LKR
         * natively and has no business guessing what an unsupported currency
         * ought to become. Null means "refuse", and refusing is the safe
         * default — a rail that invents a fallback currency converts money
         * nobody quoted.
         */
        public ?string $conversionFallbackCurrency,

        public CheckoutMode $checkoutMode,
    ) {
        if ($supportedCurrencies === []) {
            throw new \InvalidArgumentException('A rail that supports no currency cannot take a payment.');
        }

        foreach ($supportedCurrencies as $code) {
            if (preg_match('/^[A-Z]{3}$/', $code) !== 1) {
                throw new \InvalidArgumentException(sprintf(
                    'Supported currency "%s" is not an upper-case ISO 4217 code.',
                    $code,
                ));
            }
        }

        if (count(array_unique($supportedCurrencies)) !== count($supportedCurrencies)) {
            throw new \InvalidArgumentException('Supported currencies contain a duplicate.');
        }

        // Being merchant of record is not a flag that can be set on its own: it
        // *is* the bundle of remitting tax and owning chargebacks. A rail
        // claiming to be MoR without them would let a caller check the cheap
        // boolean and skip the obligations the other two describe.
        if ($isMerchantOfRecord && (!$remitsTax || !$handlesChargebacks)) {
            throw new \InvalidArgumentException(
                'A merchant of record necessarily remits tax and owns chargebacks; declaring otherwise misstates who is liable.'
            );
        }

        if (!in_array($settlementCurrency, $supportedCurrencies, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Settlement currency %s is not among the currencies this rail can bill.',
                $settlementCurrency,
            ));
        }

        if ($conversionFallbackCurrency !== null
            && !in_array($conversionFallbackCurrency, $supportedCurrencies, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Conversion fallback %s is not a currency this rail can bill.',
                $conversionFallbackCurrency,
            ));
        }
    }

    public function supports(Currency|string $currency): bool
    {
        $code = $currency instanceof Currency ? $currency->code : strtoupper($currency);

        return in_array($code, $this->supportedCurrencies, true);
    }

    /**
     * The currency this rail would actually bill a price denominated in
     * `$recordCurrency` — itself when supported, the fallback otherwise, and
     * null when the rail refuses to convert.
     *
     * Returns a currency rather than performing a conversion. Deciding *what*
     * to bill in is the rail's knowledge; deciding at *what rate* is a priced,
     * snapshotted, disclosed decision that belongs to the caller.
     */
    public function chargeCurrencyFor(Currency|string $recordCurrency): ?string
    {
        $code = $recordCurrency instanceof Currency ? $recordCurrency->code : strtoupper($recordCurrency);

        return $this->supports($code) ? $code : $this->conversionFallbackCurrency;
    }

    /**
     * True when billing this price means converting it.
     *
     * The one question the whole LKR problem reduces to: an organiser is
     * credited exactly what they published only when this answers false.
     */
    public function requiresConversionFor(Currency|string $recordCurrency): bool
    {
        return !$this->supports($recordCurrency);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'is_merchant_of_record'         => $this->isMerchantOfRecord,
            'remits_tax'                    => $this->remitsTax,
            'handles_chargebacks'           => $this->handlesChargebacks,
            'reports_per_transaction_fee'   => $this->reportsPerTransactionFee,
            'supports_refunds'              => $this->supportsRefunds,
            'supported_currencies'          => $this->supportedCurrencies,
            'settlement_currency'           => $this->settlementCurrency,
            'conversion_fallback_currency'  => $this->conversionFallbackCurrency,
            'checkout_mode'                 => $this->checkoutMode->value,
        ];
    }
}
