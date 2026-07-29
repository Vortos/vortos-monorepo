<?php

declare(strict_types=1);

namespace Vortos\Paddle\Tests\Subscription;

use GuzzleHttp\Psr7\Response;
use Http\Client\HttpAsyncClient;
use Http\Promise\FulfilledPromise;
use Http\Promise\Promise;
use Paddle\SDK\Client;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Vortos\Paddle\Api\PaddleApiClientInterface;
use Vortos\Paddle\Subscription\ImmediateSubscriptionService;
use Vortos\Paddle\Subscription\Operation\SubscriptionItemRequest;
use Vortos\Paddle\Subscription\SubscriptionUpdatePreview;
use Vortos\Paddle\Subscription\Operation\UpdateSubscriptionRequest;
use Vortos\Paddle\ValueObject\PaddlePriceId;
use Vortos\Paddle\ValueObject\PaddleSubscriptionId;
use Vortos\Paddle\ValueObject\ProrationAction;
use Vortos\Paddle\ValueObject\ProrationMode;

/**
 * What actually leaves the process.
 *
 * The sibling tests assert that the API client was called, which is why an update
 * that silently dropped its items passed them for as long as it existed: changing a
 * plan sent Paddle a request that named no prices and changed nothing. These assert
 * the serialized body instead, so the mapping cannot rot without a failure.
 */
final class SubscriptionUpdatePayloadTest extends TestCase
{
    public function test_update_sends_the_items_it_was_given(): void
    {
        $body = $this->captureUpdateBody(new UpdateSubscriptionRequest(
            items: [
                new SubscriptionItemRequest(PaddlePriceId::of('pri_pro_monthly'), 1),
            ],
            prorationMode: ProrationMode::ProratedImmediately,
        ));

        self::assertArrayHasKey('items', $body, 'a plan change is a change of items');
        self::assertSame('pri_pro_monthly', $body['items'][0]['price_id']);
        self::assertSame(1, $body['items'][0]['quantity']);
        self::assertSame('prorated_immediately', $body['proration_billing_mode']);
    }

    /**
     * Absent items must stay absent. An empty list is not the same request: Paddle
     * reads it as "this subscription now contains nothing".
     */
    public function test_update_without_items_omits_them_entirely(): void
    {
        $body = $this->captureUpdateBody(new UpdateSubscriptionRequest(
            prorationMode: ProrationMode::ProratedNextBillingPeriod,
        ));

        self::assertArrayNotHasKey('items', $body);
        self::assertSame('prorated_next_billing_period', $body['proration_billing_mode']);
    }

    public function test_update_sends_next_billed_at_when_given(): void
    {
        $body = $this->captureUpdateBody(new UpdateSubscriptionRequest(
            nextBilledAt: '2026-09-01T00:00:00Z',
        ));

        self::assertArrayHasKey('next_billed_at', $body);
        self::assertStringStartsWith('2026-09-01', (string) $body['next_billed_at']);
    }

    public function test_multiple_items_keep_their_order_and_quantities(): void
    {
        $body = $this->captureUpdateBody(new UpdateSubscriptionRequest(
            items: [
                new SubscriptionItemRequest(PaddlePriceId::of('pri_seat'), 12),
                new SubscriptionItemRequest(PaddlePriceId::of('pri_addon'), 3),
            ],
        ));

        self::assertCount(2, $body['items']);
        self::assertSame('pri_seat', $body['items'][0]['price_id']);
        self::assertSame(12, $body['items'][0]['quantity']);
        self::assertSame('pri_addon', $body['items'][1]['price_id']);
        self::assertSame(3, $body['items'][1]['quantity']);
    }

    /**
     * The mapped subscription has to expose its priced lines: they are the only place
     * the current plan and billing cycle can be read from, and a caller that cannot
     * read them cannot tell a real plan change from a no-op it would still bill for.
     */
    public function test_a_mapped_subscription_exposes_the_prices_it_bills(): void
    {
        $payload = SubscriptionFixture::payload();
        $payload['items'] = [SubscriptionFixture::item('pri_pro_monthly', 'pro_sqoura', 2)];

        $subscription = \Vortos\Paddle\Subscription\Subscription::fromSdk(
            \Paddle\SDK\Entities\Subscription::from($payload),
        );

        self::assertCount(1, $subscription->items);
        self::assertSame('pri_pro_monthly', $subscription->items[0]->priceId->value);
        self::assertSame('pro_sqoura', $subscription->items[0]->productId->value);
        self::assertSame(2, $subscription->items[0]->quantity);
        self::assertTrue($subscription->items[0]->recurring);
    }

    /**
     * A downgrade credits the customer. Paddle sends that as an unsigned amount plus
     * an action, so a preview that keeps only the amount says the customer owes the
     * exact sum they are being given back.
     */
    public function test_a_credit_preview_is_not_reported_as_a_charge(): void
    {
        $preview = $this->capturePreview('credit', '144054');

        self::assertTrue($preview->isCredit());
        self::assertSame(ProrationAction::Credit, $preview->immediateAction);
        self::assertSame('144054', $preview->immediateTotal, 'the raw amount stays as Paddle sent it');
        self::assertSame('-144054', $preview->signedImmediateTotal());
    }

    public function test_a_charge_preview_stays_positive(): void
    {
        $preview = $this->capturePreview('charge', '5000');

        self::assertFalse($preview->isCredit());
        self::assertSame('5000', $preview->signedImmediateTotal());
    }

    /**
     * The next-invoice figure lives under details.totals, and reading it from the
     * wrong place returned null which fell through to '0' — quoting a customer a
     * next invoice of nothing however much they were about to owe.
     */
    public function test_the_next_invoice_total_is_read_from_the_right_place(): void
    {
        $preview = $this->capturePreview('credit', '144018');

        self::assertSame('4900', $preview->nextBillingTotal);
    }

    /** A credit goes to the customer's balance, not back to their card. */
    public function test_a_credit_reports_what_lands_on_the_balance(): void
    {
        self::assertSame('144018', $this->capturePreview('credit', '144018')->creditToBalance);
    }

    /** Zero has no direction, and must not come back as "-0". */
    public function test_a_zero_proration_has_no_sign(): void
    {
        self::assertSame('0', $this->capturePreview('credit', '0')->signedImmediateTotal());
    }

    // ── Harness ───────────────────────────────────────────────────────────────

    /** Runs previewUpdate against a canned Paddle preview response. */
    private function capturePreview(string $action, string $amount): SubscriptionUpdatePreview
    {
        $http = new class ($action, $amount) implements HttpAsyncClient {
            public function __construct(private string $action, private string $amount) {}

            public function sendAsyncRequest(RequestInterface $request): Promise
            {
                return new FulfilledPromise(new Response(
                    200,
                    ['Content-Type' => 'application/json'],
                    json_encode(
                        ['data' => SubscriptionFixture::previewPayload($this->action, $this->amount)],
                        JSON_THROW_ON_ERROR,
                    ),
                ));
            }
        };

        $sdk = new Client('pdl_test_key', httpClient: $http);

        $client = $this->createMock(PaddleApiClientInterface::class);
        $client->method('sdk')->willReturn($sdk);
        $client->method('call')->willReturnCallback(static fn (callable $op): mixed => $op());

        return (new ImmediateSubscriptionService($client))->previewUpdate(
            PaddleSubscriptionId::of('sub_123'),
            new UpdateSubscriptionRequest(
                items: [new SubscriptionItemRequest(PaddlePriceId::of('pri_club_monthly'), 1)],
                prorationMode: ProrationMode::ProratedImmediately,
            ),
        );
    }

    /**
     * Runs a real update through the real SDK against a captured HTTP request, and
     * hands back the decoded JSON body.
     *
     * @return array<string, mixed>
     */
    private function captureUpdateBody(UpdateSubscriptionRequest $request): array
    {
        $captured = null;

        $http = new class ($captured) implements HttpAsyncClient {
            public function __construct(private mixed &$captured) {}

            public function sendAsyncRequest(RequestInterface $request): Promise
            {
                $this->captured = (string) $request->getBody();

                return new FulfilledPromise(new Response(
                    200,
                    ['Content-Type' => 'application/json'],
                    json_encode(['data' => SubscriptionFixture::payload()], JSON_THROW_ON_ERROR),
                ));
            }
        };

        $sdk = new Client('pdl_test_key', httpClient: $http);

        $client = $this->createMock(PaddleApiClientInterface::class);
        $client->method('sdk')->willReturn($sdk);
        // The real client wraps operations in retries and error mapping; here it only
        // needs to run the closure so the SDK call actually happens.
        $client->method('call')->willReturnCallback(static fn (callable $op): mixed => $op());

        (new ImmediateSubscriptionService($client))
            ->update(PaddleSubscriptionId::of('sub_123'), $request);

        self::assertIsString($captured, 'no HTTP request was made');

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($captured, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}

/** A subscription response complete enough for the SDK to hydrate. */
final class SubscriptionFixture
{
    /** @return array<string, mixed> */
    public static function payload(): array
    {
        return [
            'id'              => 'sub_123',
            'status'          => 'active',
            'customer_id'     => 'ctm_test',
            'address_id'      => 'add_test',
            'business_id'     => null,
            'currency_code'   => 'USD',
            'created_at'      => '2024-01-01T00:00:00.000000Z',
            'updated_at'      => '2024-01-02T00:00:00.000000Z',
            'started_at'      => null,
            'first_billed_at' => null,
            'next_billed_at'  => null,
            'paused_at'       => null,
            'canceled_at'     => null,
            'discount'        => null,
            'collection_mode' => 'automatic',
            'billing_details' => null,
            'current_billing_period' => null,
            'billing_cycle'   => ['interval' => 'month', 'frequency' => 1],
            'scheduled_change' => null,
            'management_urls' => null,
            'items'           => [],
            'custom_data'     => null,
            'import_meta'     => null,
            'next_transaction' => null,
            'recurring_transaction_details' => null,
        ];
    }

    /**
     * A subscription-preview response, as `previewUpdate` receives it.
     *
     * @return array<string, mixed>
     */
    public static function previewPayload(string $action, string $amount): array
    {
        $payload = self::payload();
        unset($payload['id'], $payload['import_meta']);
        // A preview is not a subscription: it has no id, and its management urls are
        // required rather than nullable.
        $payload['management_urls'] = [
            'update_payment_method' => null,
            'cancel'                => 'https://paddle.test/cancel',
        ];
        // Shaped like a real sandbox response: the immediate transaction settles to
        // zero and pushes the credit onto the balance, and the next invoice is the
        // new plan's full price.
        $payload['immediate_transaction'] = self::transactionPreview('-144018', '0', '144018');
        $payload['next_transaction'] = self::transactionPreview('4900', '4900', '0');
        $payload['recurring_transaction_details'] = null;
        $payload['update_summary'] = [
            'credit' => ['amount' => $amount, 'currency_code' => 'GBP'],
            'charge' => ['amount' => '0', 'currency_code' => 'GBP'],
            'result' => ['action' => $action, 'amount' => $amount, 'currency_code' => 'GBP'],
        ];

        return $payload;
    }

    /**
     * A transaction preview block, as it appears under immediate_/next_transaction.
     *
     * @return array<string, mixed>
     */
    public static function transactionPreview(string $total, string $balance, string $creditToBalance): array
    {
        return [
            'billing_period' => [
                'starts_at' => '2026-08-29T09:03:57.901Z',
                'ends_at'   => '2026-09-29T09:03:57.901Z',
            ],
            'details' => [
                'tax_rates_used' => [],
                'totals' => [
                    'subtotal'          => $total,
                    'discount'          => '0',
                    'tax'               => '0',
                    'total'             => $total,
                    'credit'            => '0',
                    'balance'           => $balance,
                    'grand_total'       => $balance,
                    'grand_total_tax'   => '0',
                    'fee'               => null,
                    'earnings'          => null,
                    'currency_code'     => 'GBP',
                    'credit_to_balance' => $creditToBalance,
                ],
                'line_items' => [],
            ],
            'adjustments' => [],
        ];
    }

    /** @return array<string, mixed> */
    public static function item(string $priceId, string $productId, int $quantity): array
    {
        return [
            'status'               => 'active',
            'quantity'             => $quantity,
            'recurring'            => true,
            'created_at'           => '2024-01-01T00:00:00.000000Z',
            'updated_at'           => '2024-01-02T00:00:00.000000Z',
            'previously_billed_at' => null,
            'next_billed_at'       => null,
            'trial_dates'          => null,
            'price'                => [
                'id'                   => $priceId,
                'product_id'           => $productId,
                'description'          => 'Pro monthly',
                'type'                 => 'standard',
                'name'                 => 'Pro',
                'billing_cycle'        => ['interval' => 'month', 'frequency' => 1],
                'trial_period'         => null,
                'tax_mode'             => 'account_setting',
                'unit_price'           => ['amount' => '14900', 'currency_code' => 'GBP'],
                'unit_price_overrides' => [],
                'quantity'             => ['minimum' => 1, 'maximum' => 100],
                'status'               => 'active',
                'custom_data'          => null,
                'import_meta'          => null,
                'created_at'           => '2024-01-01T00:00:00.000000Z',
                'updated_at'           => '2024-01-01T00:00:00.000000Z',
            ],
            'product' => [
                'id'           => $productId,
                'name'         => 'Sqoura Pro',
                'description'  => null,
                'type'         => 'standard',
                'tax_category' => 'standard',
                'image_url'    => null,
                'custom_data'  => null,
                'status'       => 'active',
                'import_meta'  => null,
                'created_at'   => '2024-01-01T00:00:00.000000Z',
                'updated_at'   => '2024-01-01T00:00:00.000000Z',
            ],
        ];
    }
}
