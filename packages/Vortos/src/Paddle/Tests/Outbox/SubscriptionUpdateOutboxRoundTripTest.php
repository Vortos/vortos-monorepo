<?php

declare(strict_types=1);

namespace Vortos\Paddle\Tests\Outbox;

use PHPUnit\Framework\TestCase;
use Vortos\Paddle\Catalog\Contract\ImmediateDiscountServiceInterface;
use Vortos\Paddle\Catalog\Contract\ImmediatePriceServiceInterface;
use Vortos\Paddle\Catalog\Contract\ImmediateProductServiceInterface;
use Vortos\Paddle\Customer\Contract\ImmediateAddressServiceInterface;
use Vortos\Paddle\Customer\Contract\ImmediateBusinessServiceInterface;
use Vortos\Paddle\Customer\Contract\ImmediateCustomerServiceInterface;
use Vortos\Paddle\Outbox\PaddleApiOutboxDispatcher;
use Vortos\Paddle\Outbox\PaddleOutboxWriterInterface;
use Vortos\Paddle\Subscription\Contract\ImmediateSubscriptionServiceInterface;
use Vortos\Paddle\Subscription\Operation\SubscriptionItemRequest;
use Vortos\Paddle\Subscription\Operation\UpdateSubscriptionRequest;
use Vortos\Paddle\Subscription\TransactionalSubscriptionService;
use Vortos\Paddle\Transaction\Contract\ImmediateAdjustmentServiceInterface;
use Vortos\Paddle\Transaction\Contract\ImmediateTransactionServiceInterface;
use Vortos\Paddle\ValueObject\PaddlePriceId;
use Vortos\Paddle\ValueObject\PaddleSubscriptionId;
use Vortos\Paddle\ValueObject\ProrationMode;

/**
 * A queued plan change has to survive the queue.
 *
 * The deferred path writes the update to an outbox and replays it later, so the
 * items have to be in the payload and be rebuilt on the way out. They were in
 * neither: a plan change taken inside a transaction reached Paddle as an update
 * that named no prices.
 */
final class SubscriptionUpdateOutboxRoundTripTest extends TestCase
{
    public function test_queued_update_carries_its_items_and_they_are_replayed(): void
    {
        $queued = null;

        $outbox = $this->createMock(PaddleOutboxWriterInterface::class);
        $outbox->method('queue')->willReturnCallback(
            function (string $operation, array $payload) use (&$queued): void {
                $queued = [$operation, $payload];
            },
        );

        (new TransactionalSubscriptionService(
            $outbox,
            $this->createMock(ImmediateSubscriptionServiceInterface::class),
        ))->update(
            PaddleSubscriptionId::of('sub_123'),
            new UpdateSubscriptionRequest(
                items: [new SubscriptionItemRequest(PaddlePriceId::of('pri_pro_monthly'), 2)],
                prorationMode: ProrationMode::ProratedImmediately,
            ),
        );

        self::assertIsArray($queued);
        [$operation, $payload] = $queued;
        self::assertSame('subscription.update', $operation);
        self::assertSame(
            [['priceId' => 'pri_pro_monthly', 'quantity' => 2]],
            $payload['items'],
            'the items must survive being written to the outbox',
        );

        // …and come back out as the request the immediate service will send.
        $replayed = null;
        $subscriptions = $this->createMock(ImmediateSubscriptionServiceInterface::class);
        $subscriptions->method('update')->willReturnCallback(
            function (PaddleSubscriptionId $id, UpdateSubscriptionRequest $request) use (&$replayed): void {
                $replayed = $request;
            },
        );

        $this->dispatcher($subscriptions)->dispatch($operation, $payload);

        self::assertInstanceOf(UpdateSubscriptionRequest::class, $replayed);
        self::assertCount(1, $replayed->items);
        self::assertSame('pri_pro_monthly', $replayed->items[0]->priceId->value);
        self::assertSame(2, $replayed->items[0]->quantity);
        self::assertSame(ProrationMode::ProratedImmediately, $replayed->prorationMode);
    }

    /**
     * An update that was never about the items must not acquire an empty list on the
     * way through — Paddle reads that as emptying the subscription.
     */
    public function test_a_queued_update_without_items_replays_without_them(): void
    {
        $replayed = null;
        $subscriptions = $this->createMock(ImmediateSubscriptionServiceInterface::class);
        $subscriptions->method('update')->willReturnCallback(
            function (PaddleSubscriptionId $id, UpdateSubscriptionRequest $request) use (&$replayed): void {
                $replayed = $request;
            },
        );

        $this->dispatcher($subscriptions)->dispatch('subscription.update', [
            'id'            => 'sub_123',
            'prorationMode' => 'do_not_bill',
            'nextBilledAt'  => null,
            'items'         => null,
        ]);

        self::assertInstanceOf(UpdateSubscriptionRequest::class, $replayed);
        self::assertNull($replayed->items);
    }

    /** Payloads written before items were carried must still replay. */
    public function test_a_legacy_payload_without_the_items_key_replays(): void
    {
        $replayed = null;
        $subscriptions = $this->createMock(ImmediateSubscriptionServiceInterface::class);
        $subscriptions->method('update')->willReturnCallback(
            function (PaddleSubscriptionId $id, UpdateSubscriptionRequest $request) use (&$replayed): void {
                $replayed = $request;
            },
        );

        $this->dispatcher($subscriptions)->dispatch('subscription.update', [
            'id'            => 'sub_123',
            'prorationMode' => null,
        ]);

        self::assertInstanceOf(UpdateSubscriptionRequest::class, $replayed);
        self::assertNull($replayed->items);
    }

    private function dispatcher(ImmediateSubscriptionServiceInterface $subscriptions): PaddleApiOutboxDispatcher
    {
        return new PaddleApiOutboxDispatcher(
            $this->createMock(ImmediateCustomerServiceInterface::class),
            $this->createMock(ImmediateAddressServiceInterface::class),
            $this->createMock(ImmediateBusinessServiceInterface::class),
            $this->createMock(ImmediateTransactionServiceInterface::class),
            $this->createMock(ImmediateAdjustmentServiceInterface::class),
            $this->createMock(ImmediateProductServiceInterface::class),
            $this->createMock(ImmediatePriceServiceInterface::class),
            $this->createMock(ImmediateDiscountServiceInterface::class),
            $subscriptions,
        );
    }
}
