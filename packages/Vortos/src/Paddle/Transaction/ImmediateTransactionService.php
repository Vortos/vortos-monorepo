<?php

declare(strict_types=1);

namespace Vortos\Paddle\Transaction;

use Paddle\SDK\Resources\Transactions\Operations\Create\TransactionCreateItem as SdkCreateItem;
use Paddle\SDK\Resources\Transactions\Operations\CreateTransaction;
use Paddle\SDK\Resources\Transactions\Operations\Preview\TransactionItemPreviewWithPriceId;
use Paddle\SDK\Resources\Transactions\Operations\PreviewTransaction;
use Paddle\SDK\Resources\Transactions\Operations\UpdateTransaction;
use Vortos\Paddle\Api\PaddleApiClientInterface;
use Vortos\Paddle\Transaction\Contract\ImmediateTransactionServiceInterface;
use Vortos\Paddle\Transaction\Operation\CreateTransactionRequest;
use Vortos\Paddle\Transaction\Operation\UpdateTransactionRequest;
use Vortos\Paddle\ValueObject\PaddleTransactionId;

final class ImmediateTransactionService implements ImmediateTransactionServiceInterface
{
    public function __construct(private readonly PaddleApiClientInterface $client) {}

    public function create(CreateTransactionRequest $request): PaddleTransactionId
    {
        $items = array_map(
            fn($item) => new SdkCreateItem($item->priceId->value, $item->quantity),
            $request->items
        );

        $sdkTransaction = $this->client->call(
            fn() => $this->client->sdk()->transactions->create(
                new CreateTransaction(items: $items, customerId: $request->customerId->value)
            )
        );

        return PaddleTransactionId::of($sdkTransaction->id);
    }

    public function get(PaddleTransactionId $id): Transaction
    {
        $sdk = $this->client->call(
            fn() => $this->client->sdk()->transactions->get($id->value)
        );

        return Transaction::fromSdk($sdk);
    }

    public function update(PaddleTransactionId $id, UpdateTransactionRequest $request): void
    {
        $this->client->call(
            fn() => $this->client->sdk()->transactions->update(
                $id->value,
                new UpdateTransaction()
            )
        );
    }

    public function preview(CreateTransactionRequest $request): TransactionPreviewResult
    {
        $items = array_map(
            fn($item) => new TransactionItemPreviewWithPriceId($item->priceId->value, $item->quantity),
            $request->items
        );

        $sdkPreview = $this->client->call(
            fn() => $this->client->sdk()->transactions->preview(
                new PreviewTransaction(
                    items:      $items,
                    customerId: $request->customerId->value,
                )
            )
        );

        return new TransactionPreviewResult(
            subtotal:     $sdkPreview->details->totals->subtotal,
            tax:          $sdkPreview->details->totals->tax,
            total:        $sdkPreview->details->totals->total,
            currencyCode: (string) $sdkPreview->currencyCode,
            lineItems:    array_map(
                fn($item) => new TransactionLineItem(
                    id:       '',
                    priceId:  $item->priceId ?? '',
                    quantity: $item->quantity,
                    total:    $item->totals->total,
                    subtotal: $item->totals->subtotal,
                    tax:      $item->totals->tax,
                ),
                $sdkPreview->details->lineItems
            ),
        );
    }

    public function getInvoicePdfUrl(PaddleTransactionId $id): string
    {
        $data = $this->client->call(
            fn() => $this->client->sdk()->transactions->getInvoicePDF($id->value)
        );

        return $data->url;
    }

    public function list(): array
    {
        $collection = $this->client->call(
            fn() => $this->client->sdk()->transactions->list()
        );

        return array_map(
            fn($sdk) => Transaction::fromSdk($sdk),
            iterator_to_array($collection)
        );
    }
}
