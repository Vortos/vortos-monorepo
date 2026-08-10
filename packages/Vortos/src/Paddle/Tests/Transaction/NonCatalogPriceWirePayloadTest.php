<?php

declare(strict_types=1);

namespace Vortos\Paddle\Tests\Transaction;

use Http\Client\HttpAsyncClient;
use Http\Promise\FulfilledPromise;
use Http\Promise\Promise;
use Paddle\SDK\Client as SdkClient;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Http\Discovery\Psr17FactoryDiscovery;
use Vortos\Paddle\Api\PaddleApiClientInterface;
use Vortos\Paddle\Transaction\ImmediateTransactionService;
use Vortos\Paddle\Transaction\Operation\CreateTransactionRequest;
use Vortos\Paddle\Transaction\Operation\TransactionItemRequest;
use Vortos\Paddle\ValueObject\Money;
use Vortos\Paddle\ValueObject\PaddleCustomerId;

/**
 * Asserts what actually reaches Paddle for a non-catalog line.
 *
 * The two fields under test are only observable on the wire: `name` and
 * `quantity` are `Undefined` by default in the SDK's price object and are
 * stripped before encoding, so an assertion against the request object would
 * pass whether or not they were set. Every earlier test in this package
 * asserted on the returned id, which is why both defects shipped. This one
 * drives a real SDK client against a capturing HTTP client and reads the JSON
 * body Paddle would have received.
 */
final class NonCatalogPriceWirePayloadTest extends TestCase
{
    /**
     * Runs one create() and returns the decoded request body.
     *
     * @param  list<TransactionItemRequest> $items
     * @return array<string, mixed>
     */
    private function bodyFor(array $items): array
    {
        $captured = null;

        $http = new class ($captured) implements HttpAsyncClient {
            /**
             * The minimum the SDK's Transaction hydrator accepts. Only the
             * request matters to these tests; this exists so create() returns
             * instead of tripping over a half-built entity.
             */
            private const TRANSACTION = [
                'id'                        => 'txn_wire',
                'status'                    => 'draft',
                'customer_id'               => 'ctm_wire',
                'address_id'                => null,
                'business_id'               => null,
                'custom_data'               => null,
                'currency_code'             => 'USD',
                'origin'                    => 'api',
                'subscription_id'           => null,
                'invoice_id'                => null,
                'invoice_number'            => null,
                'collection_mode'           => 'automatic',
                'discount_id'               => null,
                'billing_details'           => null,
                'billing_period'            => null,
                'items'                     => [],
                'details'                   => [
                    'tax_rates_used' => [],
                    'totals'         => [
                        'subtotal'          => '600000',
                        'discount'          => '0',
                        'tax'               => '0',
                        'total'             => '600000',
                        'credit'            => '0',
                        'balance'           => '600000',
                        'grand_total'       => '600000',
                        'fee'               => null,
                        'earnings'          => null,
                        'currency_code'     => 'USD',
                        'credit_to_balance' => '0',
                        'grand_total_tax'   => '0',
                    ],
                    'line_items'     => [],
                ],
                'payments'                  => [],
                'checkout'                  => null,
                'created_at'                => '2026-08-10T00:00:00.000000Z',
                'updated_at'                => '2026-08-10T00:00:00.000000Z',
                'billed_at'                 => null,
                'address'                   => null,
                'adjustments'               => [],
                'adjustments_totals'        => null,
                'business'                  => null,
                'customer'                  => null,
                'discount'                  => null,
                'available_payment_methods' => [],
                'revised_at'                => null,
            ];

            public function __construct(public ?RequestInterface &$captured) {}

            public function sendAsyncRequest(RequestInterface $request): Promise
            {
                $this->captured = $request;

                $factory = Psr17FactoryDiscovery::findResponseFactory();
                $stream  = Psr17FactoryDiscovery::findStreamFactory();

                $body = json_encode([
                    'data' => self::TRANSACTION,
                    'meta' => ['request_id' => 'req_1'],
                ], JSON_THROW_ON_ERROR);

                return new FulfilledPromise(
                    $factory->createResponse(200)
                        ->withHeader('Content-Type', 'application/json')
                        ->withBody($stream->createStream($body)),
                );
            }
        };

        $sdk = new SdkClient('pdl_test_key', httpClient: $http);

        // `call` is the retry/translate wrapper in production; here it only has
        // to run the operation so the SDK reaches the capturing transport.
        $client = new class ($sdk) implements PaddleApiClientInterface {
            public function __construct(private readonly SdkClient $sdk) {}

            public function sdk(): SdkClient
            {
                return $this->sdk;
            }

            public function call(callable $operation): mixed
            {
                return $operation();
            }
        };

        (new ImmediateTransactionService($client))->create(new CreateTransactionRequest(
            customerId: PaddleCustomerId::of('ctm_wire'),
            items:      $items,
        ));

        $this->assertInstanceOf(RequestInterface::class, $http->captured);

        return json_decode((string) $http->captured->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** The defect in the screenshot: both lines printed the shared product name. */
    public function test_each_line_carries_its_own_payer_facing_name(): void
    {
        $body = $this->bodyFor([
            TransactionItemRequest::nonCatalog(
                productId:   'pro_shared',
                unitPrice:   new Money(600000, 'USD'),
                description: 'Tournament registration',
            ),
            TransactionItemRequest::nonCatalog(
                productId:   'pro_shared',
                unitPrice:   new Money(45215, 'USD'),
                description: 'Processing & platform fee',
            ),
        ]);

        $names = array_map(fn(array $i): mixed => $i['price']['name'] ?? null, $body['items']);

        $this->assertSame(['Tournament registration', 'Processing & platform fee'], $names);
        $this->assertCount(2, array_unique($names), 'Both lines would render identically to the payer.');
    }

    public function test_name_falls_back_to_the_description_rather_than_being_omitted(): void
    {
        $body = $this->bodyFor([
            TransactionItemRequest::nonCatalog(
                productId:   'pro_shared',
                unitPrice:   new Money(600000, 'USD'),
                description: 'Tournament registration',
            ),
        ]);

        // Absent, Paddle substitutes the product name — the whole bug.
        $this->assertArrayHasKey('name', $body['items'][0]['price']);
        $this->assertSame('Tournament registration', $body['items'][0]['price']['name']);
    }

    public function test_an_explicit_name_overrides_the_internal_description(): void
    {
        $body = $this->bodyFor([
            TransactionItemRequest::nonCatalog(
                productId:   'pro_shared',
                unitPrice:   new Money(600000, 'USD'),
                description: 'REG-2026-000017 athlete fee',
                name:        'Tournament registration',
            ),
        ]);

        $price = $body['items'][0]['price'];

        $this->assertSame('Tournament registration', $price['name']);
        $this->assertSame('REG-2026-000017 athlete fee', $price['description']);
    }

    /** The stepper and the remove button in the screenshot. */
    public function test_quantity_is_pinned_so_the_payer_cannot_alter_a_server_priced_line(): void
    {
        $body = $this->bodyFor([
            TransactionItemRequest::nonCatalog(
                productId:   'pro_shared',
                unitPrice:   new Money(600000, 'USD'),
                description: 'Tournament registration',
            ),
        ]);

        $this->assertSame(
            ['minimum' => 1, 'maximum' => 1],
            $body['items'][0]['price']['quantity'],
        );
    }

    public function test_a_pinned_multi_unit_line_pins_to_that_quantity(): void
    {
        $body = $this->bodyFor([
            TransactionItemRequest::nonCatalog(
                productId:   'pro_shared',
                unitPrice:   new Money(600000, 'USD'),
                quantity:    3,
                description: 'Tournament registration',
            ),
        ]);

        $this->assertSame(
            ['minimum' => 3, 'maximum' => 3],
            $body['items'][0]['price']['quantity'],
        );
        $this->assertSame(3, $body['items'][0]['quantity']);
    }

    public function test_an_opted_out_line_leaves_paddles_default_bounds_alone(): void
    {
        $body = $this->bodyFor([
            TransactionItemRequest::nonCatalog(
                productId:     'pro_shared',
                unitPrice:     new Money(600000, 'USD'),
                description:   'Merchandise',
                fixedQuantity: false,
            ),
        ]);

        $this->assertArrayNotHasKey('quantity', $body['items'][0]['price']);
    }
}
