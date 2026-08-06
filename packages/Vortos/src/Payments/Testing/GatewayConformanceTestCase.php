<?php

declare(strict_types=1);

namespace Vortos\Payments\Testing;

use PHPUnit\Framework\TestCase;
use Vortos\Payments\Contract\GatewayInterface;
use Vortos\Payments\Contract\SignatureVerifierInterface;
use Vortos\Payments\Enum\CheckoutMode;
use Vortos\Payments\Exception\CurrencyNotSupportedException;
use Vortos\Payments\Exception\RefundNotSupportedException;
use Vortos\Payments\Exception\SignatureVerificationException;
use Vortos\Payments\ValueObject\ChargeRequest;
use Vortos\Payments\ValueObject\Currency;
use Vortos\Payments\ValueObject\Money;
use Vortos\Payments\ValueObject\RefundRequest;
use Vortos\Payments\Webhook\SignedPayload;

/**
 * The suite every payment rail must pass before it is allowed to take money.
 *
 * ── Why a shared suite instead of per-rail tests ──────────────────────────
 * Each rail's own tests answer "does this adapter work". This one answers a
 * different and more important question: "is this adapter *interchangeable*".
 * The routing layer picks a rail by currency and hands it a priced charge
 * without knowing which one it got, so every property that layer relies on has
 * to hold identically everywhere. Written per rail, those properties get
 * written slightly differently, and the one that gets written slightly wrong
 * is the one that converts a currency it should have refused.
 *
 * Subclasses supply a gateway wired to a fake transport. These are not
 * integration tests and must not touch a network — a conformance run has to be
 * fast enough to sit in the default suite, or it stops being run.
 */
abstract class GatewayConformanceTestCase extends TestCase
{
    /** The gateway under test, wired to a fake transport. */
    abstract protected function gateway(): GatewayInterface;

    /**
     * A complete, valid charge request denominated in `$currency`.
     *
     * Called with both a supported and an unsupported currency, so it must not
     * itself reject anything — the point of the unsupported case is to prove
     * the *gateway* refuses.
     */
    abstract protected function chargeRequestIn(Currency $currency): ChargeRequest;

    /** Null when this rail has no webhook signature to verify. */
    abstract protected function signatureVerifier(): ?SignatureVerifierInterface;

    /** A payload the verifier must accept. Null when there is no verifier. */
    abstract protected function validSignedPayload(): ?SignedPayload;

    /**
     * The signed field carrying the charge amount, for form-signed rails.
     *
     * When named, the suite proves that a payload with a *valid-looking*
     * structure but a mutated amount is rejected — the single highest-value
     * webhook attack, because it is the one that settles a real payment for a
     * number we never charged.
     */
    protected function signedAmountField(): ?string
    {
        return null;
    }

    /**
     * A captured charge to attempt an over-refund against.
     *
     * @return array{gatewayReference: string, capturedMinor: int, currency: string}|null
     */
    protected function capturedChargeFixture(): ?array
    {
        return null;
    }

    // ─── Identity and capabilities ───────────────────────────────────────

    public function testIdIsAStableMachineIdentifier(): void
    {
        $id = $this->gateway()->id();

        self::assertNotSame('', trim($id), 'A gateway needs an id; it is persisted on every ledger entry.');
        self::assertSame(
            strtolower($id),
            $id,
            'Gateway ids are lower-case: they are compared as strings against stored history, and a case change silently orphans it.',
        );
        self::assertDoesNotMatchRegularExpression('/\s/', $id, 'A gateway id must not contain whitespace.');
    }

    public function testCapabilitiesAreSideEffectFreeAndRepeatable(): void
    {
        $gateway = $this->gateway();

        // Routing calls this on every checkout. If it were to hit a network, or
        // memoise onto a property, four long-lived workers would each hold a
        // different answer to "can this rail bill LKR".
        self::assertEquals(
            $gateway->capabilities()->toArray(),
            $gateway->capabilities()->toArray(),
            'capabilities() must return the same answer every time it is called.',
        );
    }

    public function testMerchantOfRecordStatusIsStatedCoherently(): void
    {
        $capabilities = $this->gateway()->capabilities();

        if ($capabilities->isMerchantOfRecord) {
            self::assertTrue($capabilities->remitsTax, 'A merchant of record remits the payer\'s tax.');
            self::assertTrue($capabilities->handlesChargebacks, 'A merchant of record owns chargeback liability.');

            return;
        }

        // Not an assertion about correctness — a gateway rail legitimately has
        // these false. It is here so that the tax and chargeback posture of
        // every rail is written down somewhere a reviewer will read.
        self::assertFalse(
            $capabilities->remitsTax && $capabilities->handlesChargebacks,
            'A rail that remits tax and owns chargebacks is a merchant of record; say so, because downstream liability decisions read that flag.',
        );
    }

    public function testSettlementCurrencyIsOneTheRailCanBill(): void
    {
        $capabilities = $this->gateway()->capabilities();

        self::assertTrue(
            $capabilities->supports($capabilities->settlementCurrency),
            'A rail cannot settle in a currency it cannot bill.',
        );
    }

    // ─── Charging ────────────────────────────────────────────────────────

    public function testChargeRoundTripsTheExactAmountInMinorUnits(): void
    {
        $capabilities = $this->gateway()->capabilities();
        $currency     = Currency::of($capabilities->supportedCurrencies[0]);
        $request      = $this->chargeRequestIn($currency);

        $result = $this->gateway()->createCharge($request);

        // Not assertEquals on a formatted amount: the failure this catches is a
        // rail adapter that rounds, truncates or re-scales, and every one of
        // those survives a comparison made after formatting.
        self::assertSame(
            $request->total->minorUnits,
            $result->total->minorUnits,
            'The charge total changed on its way to the rail. A payer must be asked for exactly what was priced.',
        );
        self::assertSame($request->total->currency->code, $result->total->currency->code);
        self::assertSame($request->reference, $result->reference, 'Our reference must survive the round trip; the webhook finds the payment by it.');
        self::assertNotSame('', trim($result->gatewayReference));
    }

    public function testCheckoutInstructionMatchesTheDeclaredMode(): void
    {
        $capabilities = $this->gateway()->capabilities();
        $currency     = Currency::of($capabilities->supportedCurrencies[0]);

        $checkout = $this->gateway()->createCharge($this->chargeRequestIn($currency))->checkout;

        self::assertSame(
            $capabilities->checkoutMode,
            $checkout->mode,
            'The instruction contradicts the declared checkout mode; a client branches on the declaration.',
        );

        match ($checkout->mode) {
            CheckoutMode::Overlay  => self::assertNotSame('', trim((string) $checkout->reference)),
            CheckoutMode::Redirect => self::assertNotSame([], $checkout->fields),
        };
    }

    public function testRefusesAnUnsupportedCurrencyRatherThanConvertingIt(): void
    {
        $unsupported = $this->anUnsupportedCurrency();

        if ($unsupported === null) {
            self::markTestSkipped('This rail bills every currency the suite knows to try.');
        }

        // The defect this guards is not hypothetical: converting inside an
        // adapter is exactly how an organisation ends up credited less than the
        // fee it published, with no quote, no snapshot and no disclosure
        // anywhere in the record.
        $this->expectException(CurrencyNotSupportedException::class);

        $this->gateway()->createCharge($this->chargeRequestIn($unsupported));
    }

    // ─── Webhook signatures ──────────────────────────────────────────────

    public function testValidSignatureIsAccepted(): void
    {
        [$verifier, $payload] = $this->signatureFixture();

        // Reaching the next line without an exception is the assertion. Counted
        // explicitly so PHPUnit does not report the test as risky, and without
        // a tautological assertTrue(true) that static analysis rightly objects
        // to.
        $verifier->verify($payload);

        $this->addToAssertionCount(1);
    }

    public function testTamperedPayloadIsRejected(): void
    {
        [$verifier, $payload] = $this->signatureFixture();

        // Mutates the body *and* every field, so the signed material has
        // certainly changed whichever of the two this rail signs. A tamper test
        // that guesses wrong passes against a verifier that checks nothing.
        $tampered = new SignedPayload(
            rawBody: $payload->rawBody . 'x',
            headers: $payload->headers,
            fields:  array_map(static fn (string $v): string => $v . 'x', $payload->fields),
        );

        $this->expectException(SignatureVerificationException::class);

        $verifier->verify($tampered);
    }

    public function testTamperedAmountIsRejected(): void
    {
        $field = $this->signedAmountField();

        if ($field === null) {
            self::markTestSkipped('This rail does not sign a form-encoded amount field.');
        }

        [$verifier, $payload] = $this->signatureFixture();

        self::assertArrayHasKey($field, $payload->fields, 'The named amount field is not in the fixture.');

        // A valid signature over a wrong amount is the attack that settles a
        // real registration for a number nobody charged. It has to fail on the
        // signature, before any amount comparison downstream gets a chance to.
        $fields         = $payload->fields;
        $fields[$field] = '1.00';

        $this->expectException(SignatureVerificationException::class);

        $verifier->verify(new SignedPayload($payload->rawBody, $payload->headers, $fields));
    }

    public function testEmptySignatureIsRejected(): void
    {
        [$verifier, $payload] = $this->signatureFixture();

        $this->expectException(SignatureVerificationException::class);

        $verifier->verify(new SignedPayload(
            rawBody: $payload->rawBody,
            headers: array_map(static fn (): string => '', $payload->headers),
            fields:  array_map(static fn (): string => '', $payload->fields),
        ));
    }

    public function testAbsentSignatureIsRejected(): void
    {
        [$verifier, $payload] = $this->signatureFixture();

        $this->expectException(SignatureVerificationException::class);

        // No headers, no fields — the shape of a bare POST from someone who
        // found the URL. It must fail before any parsing decides it is
        // interesting.
        $verifier->verify(new SignedPayload($payload->rawBody));
    }

    // ─── Refunds ─────────────────────────────────────────────────────────

    public function testRefundBeyondTheCapturedAmountIsRefused(): void
    {
        $fixture = $this->capturedChargeFixture();

        if ($fixture === null) {
            self::markTestSkipped('No captured-charge fixture supplied.');
        }

        if (!$this->gateway()->capabilities()->supportsRefunds) {
            self::markTestSkipped('This rail does not refund programmatically.');
        }

        $this->expectException(RefundNotSupportedException::class);

        $this->gateway()->refund(new RefundRequest(
            gatewayReference: $fixture['gatewayReference'],
            amount:           Money::fromMinor($fixture['capturedMinor'] + 1, $fixture['currency']),
            reason:           'conformance: over-refund must be refused, never clamped',
            idempotencyKey:   'conformance-over-refund',
        ));
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    /**
     * A currency this rail cannot bill, or null if it bills all the candidates.
     *
     * The candidates span the three ISO exponents on purpose: a rail that
     * handles two-decimal currencies and silently mangles a zero-decimal one
     * fails here rather than in production against a Japanese card.
     */
    private function anUnsupportedCurrency(): ?Currency
    {
        $capabilities = $this->gateway()->capabilities();

        foreach (['LKR', 'JPY', 'KWD', 'XOF', 'MGA', 'ZMW'] as $candidate) {
            if (!$capabilities->supports($candidate)) {
                return Currency::of($candidate);
            }
        }

        return null;
    }

    /** @return array{0: SignatureVerifierInterface, 1: SignedPayload} */
    private function signatureFixture(): array
    {
        $verifier = $this->signatureVerifier();
        $payload  = $this->validSignedPayload();

        if ($verifier === null || $payload === null) {
            self::markTestSkipped('This rail has no webhook signature scheme.');
        }

        return [$verifier, $payload];
    }
}
