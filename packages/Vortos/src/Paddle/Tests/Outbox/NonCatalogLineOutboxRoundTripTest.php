<?php

declare(strict_types=1);

namespace Vortos\Paddle\Tests\Outbox;

use PHPUnit\Framework\TestCase;
use Vortos\Paddle\Customer\Contract\ImmediateAddressServiceInterface;
use Vortos\Paddle\Customer\Contract\ImmediateBusinessServiceInterface;
use Vortos\Paddle\Customer\Contract\ImmediateCustomerServiceInterface;
use Vortos\Paddle\Catalog\Contract\ImmediateDiscountServiceInterface;
use Vortos\Paddle\Outbox\PaddleApiOutboxDispatcher;
use Vortos\Paddle\Outbox\PaddleOutboxWriterInterface;
use Vortos\Paddle\Catalog\Contract\ImmediatePriceServiceInterface;
use Vortos\Paddle\Catalog\Contract\ImmediateProductServiceInterface;
use Vortos\Paddle\Subscription\Contract\ImmediateSubscriptionServiceInterface;
use Vortos\Paddle\Transaction\Contract\ImmediateAdjustmentServiceInterface;
use Vortos\Paddle\Transaction\Contract\ImmediateTransactionServiceInterface;
use Vortos\Paddle\Transaction\Operation\CreateTransactionRequest;
use Vortos\Paddle\Transaction\Operation\TransactionItemRequest;
use Vortos\Paddle\Transaction\TransactionalTransactionService;
use Vortos\Paddle\ValueObject\Money;
use Vortos\Paddle\ValueObject\PaddleCustomerId;
use Vortos\Paddle\ValueObject\PaddleTransactionId;

/**
 * A queued charge must reach Paddle as the same charge it was when queued.
 *
 * The outbox path serialises a line to an array and rebuilds it in another
 * process. Anything the serialiser drops is silently replaced by a default on
 * the way back, so a field added to the request object but not to both halves
 * of this hop produces a checkout that differs from the direct path — and only
 * for the deferred rail, which is the harder one to notice.
 */
final class NonCatalogLineOutboxRoundTripTest extends TestCase
{
    /**
     * Queues a create, then dispatches the recorded payload.
     *
     * @param  list<TransactionItemRequest> $items
     * @return list<TransactionItemRequest> what the far side rebuilt
     */
    private function roundTrip(array $items): array
    {
        $payload = null;

        $outbox = $this->createMock(PaddleOutboxWriterInterface::class);
        $outbox->method('queue')->willReturnCallback(
            function (string $operation, array $data) use (&$payload): void {
                $payload = $data;
            },
        );

        (new TransactionalTransactionService(
            $outbox,
            $this->createMock(ImmediateTransactionServiceInterface::class),
        ))->create(new CreateTransactionRequest(
            customerId: PaddleCustomerId::of('ctm_outbox'),
            items:      $items,
        ));

        $this->assertIsArray($payload);

        // Survives the queue as JSON in production; encoded here so a value
        // that only round-trips in memory cannot pass.
        $payload = json_decode(json_encode($payload, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        $rebuilt = [];

        $transactions = $this->createMock(ImmediateTransactionServiceInterface::class);
        $transactions->method('create')->willReturnCallback(
            function (CreateTransactionRequest $request) use (&$rebuilt): PaddleTransactionId {
                $rebuilt = $request->items;

                return PaddleTransactionId::of('txn_dispatched');
            },
        );

        $this->dispatcherWith($transactions)->dispatch('transaction.create', $payload);

        return $rebuilt;
    }

    private function dispatcherWith(ImmediateTransactionServiceInterface $transactions): PaddleApiOutboxDispatcher
    {
        return new PaddleApiOutboxDispatcher(
            $this->createMock(ImmediateCustomerServiceInterface::class),
            $this->createMock(ImmediateAddressServiceInterface::class),
            $this->createMock(ImmediateBusinessServiceInterface::class),
            $transactions,
            $this->createMock(ImmediateAdjustmentServiceInterface::class),
            $this->createMock(ImmediateProductServiceInterface::class),
            $this->createMock(ImmediatePriceServiceInterface::class),
            $this->createMock(ImmediateDiscountServiceInterface::class),
            $this->createMock(ImmediateSubscriptionServiceInterface::class),
        );
    }

    public function test_the_payer_facing_name_survives_the_queue(): void
    {
        $rebuilt = $this->roundTrip([
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

        $this->assertSame(
            ['Tournament registration', 'Processing & platform fee'],
            array_map(fn(TransactionItemRequest $i): ?string => $i->name, $rebuilt),
        );
    }

    public function test_the_quantity_lock_survives_the_queue(): void
    {
        $rebuilt = $this->roundTrip([
            TransactionItemRequest::nonCatalog(
                productId:   'pro_shared',
                unitPrice:   new Money(600000, 'USD'),
                description: 'Tournament registration',
            ),
        ]);

        $this->assertTrue($rebuilt[0]->fixedQuantity);
    }

    public function test_an_opt_out_survives_the_queue_rather_than_re_locking(): void
    {
        $rebuilt = $this->roundTrip([
            TransactionItemRequest::nonCatalog(
                productId:     'pro_shared',
                unitPrice:     new Money(600000, 'USD'),
                description:   'Merchandise',
                fixedQuantity: false,
            ),
        ]);

        // `?? true` on the read side must not swallow an explicit false.
        $this->assertFalse($rebuilt[0]->fixedQuantity);
    }

    public function test_a_row_queued_before_these_fields_existed_still_dispatches(): void
    {
        $rebuilt = [];

        $transactions = $this->createMock(ImmediateTransactionServiceInterface::class);
        $transactions->method('create')->willReturnCallback(
            function (CreateTransactionRequest $request) use (&$rebuilt): PaddleTransactionId {
                $rebuilt = $request->items;

                return PaddleTransactionId::of('txn_legacy');
            },
        );

        // The exact shape written by the previous release — no name, no
        // fixedQuantity. These rows are already sitting in the outbox.
        $this->dispatcherWith($transactions)->dispatch('transaction.create', [
            'customerId' => 'ctm_legacy',
            'items'      => [[
                'productId'   => 'pro_shared',
                'unitAmount'  => 600000,
                'currency'    => 'USD',
                'description' => 'Tournament registration',
                'quantity'    => 1,
            ]],
            'customData' => null,
        ]);

        $this->assertCount(1, $rebuilt);
        $this->assertSame('Tournament registration', $rebuilt[0]->name);
        $this->assertTrue($rebuilt[0]->fixedQuantity);
    }
}
