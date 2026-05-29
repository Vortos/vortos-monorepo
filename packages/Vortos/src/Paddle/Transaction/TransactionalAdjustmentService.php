<?php

declare(strict_types=1);

namespace Vortos\Paddle\Transaction;

use Vortos\Paddle\Outbox\PaddleOutboxWriterInterface;
use Vortos\Paddle\Transaction\Contract\AdjustmentServiceInterface;
use Vortos\Paddle\Transaction\Contract\ImmediateAdjustmentServiceInterface;
use Vortos\Paddle\Transaction\Operation\CreateCreditRequest;
use Vortos\Paddle\Transaction\Operation\CreateRefundRequest;
use Vortos\Paddle\ValueObject\PaddleAdjustmentId;

final class TransactionalAdjustmentService implements AdjustmentServiceInterface
{
    public function __construct(
        private readonly PaddleOutboxWriterInterface           $outbox,
        private readonly ImmediateAdjustmentServiceInterface   $reader,
    ) {}

    public function createRefund(CreateRefundRequest $request): PaddleAdjustmentId
    {
        $id = PaddleAdjustmentId::of('outbox-' . bin2hex(random_bytes(8)));

        $this->outbox->queue('adjustment.refund', [
            'transactionId' => $request->transactionId->value,
            'reason'        => $request->reason,
            'items'         => array_map(
                fn($item) => ['lineItemId' => $item->lineItemId, 'amount' => $item->amount],
                $request->items
            ),
        ]);

        return $id;
    }

    public function createCredit(CreateCreditRequest $request): PaddleAdjustmentId
    {
        $id = PaddleAdjustmentId::of('outbox-' . bin2hex(random_bytes(8)));

        $this->outbox->queue('adjustment.credit', [
            'transactionId' => $request->transactionId->value,
            'reason'        => $request->reason,
            'items'         => array_map(
                fn($item) => ['lineItemId' => $item->lineItemId, 'amount' => $item->amount],
                $request->items
            ),
        ]);

        return $id;
    }

    public function get(PaddleAdjustmentId $id): Adjustment
    {
        return $this->reader->get($id);
    }

    public function list(): array
    {
        return $this->reader->list();
    }
}
