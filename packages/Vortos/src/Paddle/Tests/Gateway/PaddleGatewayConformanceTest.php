<?php

declare(strict_types=1);

namespace Vortos\Paddle\Tests\Gateway;

use Vortos\Paddle\Gateway\PaddleGateway;
use Vortos\Paddle\Gateway\PaddleSignatureVerifier;
use Vortos\Paddle\Tests\Gateway\Fake\FakeAdjustmentService;
use Vortos\Paddle\Tests\Gateway\Fake\FakeCustomerService;
use Vortos\Paddle\Tests\Gateway\Fake\FakeTransactionService;
use Vortos\Paddle\Webhook\WebhookVerifier;
use Vortos\Payments\Contract\GatewayInterface;
use Vortos\Payments\Contract\SignatureVerifierInterface;
use Vortos\Payments\Testing\GatewayConformanceTestCase;
use Vortos\Payments\ValueObject\ChargeLine;
use Vortos\Payments\ValueObject\ChargeRequest;
use Vortos\Payments\ValueObject\Currency;
use Vortos\Payments\ValueObject\Money;
use Vortos\Payments\ValueObject\PayerDetails;
use Vortos\Payments\Webhook\SignedPayload;

/**
 * Paddle against the suite every rail must pass.
 *
 * Wired to fakes rather than the SDK: the properties under test are the
 * adapter's own — that it refuses a currency Paddle cannot bill, that an
 * amount survives the round trip in minor units, that a tampered webhook is
 * rejected — and none of them need a network to be true or false.
 */
final class PaddleGatewayConformanceTest extends GatewayConformanceTestCase
{
    private const SECRET = 'pdl_ntfset_test_secret';

    protected function gateway(): GatewayInterface
    {
        return new PaddleGateway(
            customers:        new FakeCustomerService(),
            transactions:     new FakeTransactionService(),
            adjustments:      new FakeAdjustmentService(),
            defaultProductId: 'pro_test',
        );
    }

    protected function chargeRequestIn(Currency $currency): ChargeRequest
    {
        return new ChargeRequest(
            reference: 'reg-conformance-1',
            total:     Money::fromMinor(2_000, $currency),
            lines:     [
                new ChargeLine('Tournament registration', Money::fromMinor(1_800, $currency)),
                new ChargeLine('Processing & platform fee', Money::fromMinor(200, $currency)),
            ],
            payer:     new PayerDetails('payer@example.com', 'Nimal', 'Perera'),
        );
    }

    protected function signatureVerifier(): ?SignatureVerifierInterface
    {
        return new PaddleSignatureVerifier(new WebhookVerifier(self::SECRET));
    }

    /** @return array{gatewayReference: string, capturedMinor: int, currency: string} */
    protected function capturedChargeFixture(): array
    {
        // Matches the two line items the fake transaction carries: 1800 + 200.
        return [
            'gatewayReference' => FakeTransactionService::SETTLED_ID,
            'capturedMinor'    => 2_000,
            'currency'         => 'USD',
        ];
    }

    protected function validSignedPayload(): ?SignedPayload
    {
        $body      = '{"event_type":"transaction.completed","data":{"id":"txn_1"}}';
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp . ':' . $body, self::SECRET);

        return new SignedPayload(
            rawBody: $body,
            headers: ['paddle-signature' => sprintf('ts=%s;h1=%s', $timestamp, $signature)],
        );
    }
}
