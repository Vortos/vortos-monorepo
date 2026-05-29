<?php

declare(strict_types=1);

namespace Vortos\Paddle\Transaction;

use Paddle\SDK\Entities\Shared\Action;
use Paddle\SDK\Entities\Shared\AdjustmentType;
use Paddle\SDK\Resources\Adjustments\Operations\Create\AdjustmentItem as SdkAdjustmentItem;
use Paddle\SDK\Resources\Adjustments\Operations\CreateAdjustment;
use Vortos\Paddle\Api\PaddleApiClientInterface;
use Vortos\Paddle\Transaction\Contract\ImmediateAdjustmentServiceInterface;
use Vortos\Paddle\Transaction\Exception\OverRefundException;
use Vortos\Paddle\Transaction\Operation\CreateCreditRequest;
use Vortos\Paddle\Transaction\Operation\CreateRefundRequest;
use Vortos\Paddle\ValueObject\PaddleAdjustmentId;

final class ImmediateAdjustmentService implements ImmediateAdjustmentServiceInterface
{
    public function __construct(private readonly PaddleApiClientInterface $client) {}

    public function createRefund(CreateRefundRequest $request): PaddleAdjustmentId
    {
        $transaction = $this->client->call(
            fn() => $this->client->sdk()->transactions->get($request->transactionId->value)
        );

        $lineItemsById = [];
        foreach ($transaction->details->lineItems as $lineItem) {
            $lineItemsById[$lineItem->id] = $lineItem;
        }

        foreach ($request->items as $item) {
            if (!isset($lineItemsById[$item->lineItemId])) {
                continue;
            }
            $lineItemTotal = $lineItemsById[$item->lineItemId]->totals->total;
            if ((int) $item->amount > (int) $lineItemTotal) {
                throw new OverRefundException($item->lineItemId, $item->amount, $lineItemTotal);
            }
        }

        $sdkItems = array_map(
            fn($item) => new SdkAdjustmentItem($item->lineItemId, AdjustmentType::Partial(), $item->amount),
            $request->items
        );

        $sdkAdjustment = $this->client->call(
            fn() => $this->client->sdk()->adjustments->create(
                CreateAdjustment::partial(
                    action:        Action::Refund(),
                    items:         $sdkItems,
                    reason:        $request->reason,
                    transactionId: $request->transactionId->value,
                )
            )
        );

        return PaddleAdjustmentId::of($sdkAdjustment->id);
    }

    public function createCredit(CreateCreditRequest $request): PaddleAdjustmentId
    {
        $sdkItems = array_map(
            fn($item) => new SdkAdjustmentItem($item->lineItemId, AdjustmentType::Partial(), $item->amount),
            $request->items
        );

        $sdkAdjustment = $this->client->call(
            fn() => $this->client->sdk()->adjustments->create(
                CreateAdjustment::partial(
                    action:        Action::Credit(),
                    items:         $sdkItems,
                    reason:        $request->reason,
                    transactionId: $request->transactionId->value,
                )
            )
        );

        return PaddleAdjustmentId::of($sdkAdjustment->id);
    }

    public function get(PaddleAdjustmentId $id): Adjustment
    {
        throw new \LogicException('Paddle SDK does not support fetching a single adjustment by ID. Use list() with filters instead.');
    }

    public function list(): array
    {
        $collection = $this->client->call(
            fn() => $this->client->sdk()->adjustments->list()
        );

        return array_map(
            fn($sdk) => Adjustment::fromSdk($sdk),
            iterator_to_array($collection)
        );
    }
}
