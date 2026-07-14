<?php

declare(strict_types=1);

namespace Vortos\Paddle\Transaction;

use Vortos\Paddle\Outbox\PaddleOutboxWriterInterface;
use Vortos\Paddle\Transaction\Contract\ImmediateTransactionServiceInterface;
use Vortos\Paddle\Transaction\Contract\TransactionServiceInterface;
use Vortos\Paddle\Transaction\Operation\CreateTransactionRequest;
use Vortos\Paddle\Transaction\Operation\UpdateTransactionRequest;
use Vortos\Paddle\ValueObject\PaddleTransactionId;

final class TransactionalTransactionService implements TransactionServiceInterface
{
    public function __construct(
        private readonly PaddleOutboxWriterInterface          $outbox,
        private readonly ImmediateTransactionServiceInterface $reader,
    ) {}

    public function create(CreateTransactionRequest $request): PaddleTransactionId
    {
        $id = PaddleTransactionId::of('outbox-' . bin2hex(random_bytes(8)));

        $this->outbox->queue('transaction.create', [
            'customerId' => $request->customerId->value,
            'items'      => array_map(
                fn($item) => $item->isNonCatalog()
                    ? [
                        'productId'   => $item->productId,
                        'unitAmount'  => $item->unitPrice->amount,
                        'currency'    => $item->unitPrice->currencyCode,
                        'description' => $item->description,
                        'quantity'    => $item->quantity,
                    ]
                    : ['priceId' => $item->priceId->value, 'quantity' => $item->quantity],
                $request->items
            ),
            'customData' => $request->customData,
        ]);

        return $id;
    }

    public function get(PaddleTransactionId $id): Transaction
    {
        return $this->reader->get($id);
    }

    public function update(PaddleTransactionId $id, UpdateTransactionRequest $request): void
    {
        $this->outbox->queue('transaction.update', [
            'id'     => $id->value,
            'items'  => $request->items !== null
                ? array_map(
                    fn($item) => ['priceId' => $item->priceId->value, 'quantity' => $item->quantity],
                    $request->items
                )
                : null,
            'status' => $request->status,
        ]);
    }

    public function preview(CreateTransactionRequest $request): TransactionPreviewResult
    {
        return $this->reader->preview($request);
    }

    public function getInvoicePdfUrl(PaddleTransactionId $id): string
    {
        return $this->reader->getInvoicePdfUrl($id);
    }

    public function list(): array
    {
        return $this->reader->list();
    }
}
