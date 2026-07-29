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
use Vortos\Paddle\Subscription\Operation\UpdateSubscriptionRequest;
use Vortos\Paddle\ValueObject\PaddlePriceId;
use Vortos\Paddle\ValueObject\PaddleSubscriptionId;
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

    // ── Harness ───────────────────────────────────────────────────────────────

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
}
