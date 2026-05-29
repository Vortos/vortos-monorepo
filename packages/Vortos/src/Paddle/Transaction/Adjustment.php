<?php

declare(strict_types=1);

namespace Vortos\Paddle\Transaction;

use Vortos\Paddle\ValueObject\AdjustmentAction;
use Vortos\Paddle\ValueObject\AdjustmentStatus;
use Vortos\Paddle\ValueObject\PaddleAdjustmentId;
use Vortos\Paddle\ValueObject\PaddleCustomerId;
use Vortos\Paddle\ValueObject\PaddleTransactionId;

final class Adjustment
{
    public function __construct(
        public readonly PaddleAdjustmentId  $id,
        public readonly PaddleTransactionId $transactionId,
        public readonly PaddleCustomerId    $customerId,
        public readonly AdjustmentAction    $action,
        public readonly AdjustmentStatus    $status,
        public readonly string              $total,
        public readonly string              $currencyCode,
        public readonly string              $reason,
        public readonly \DateTimeImmutable  $createdAt,
    ) {}

    public static function fromSdk(\Paddle\SDK\Entities\Adjustment $sdk): self
    {
        return new self(
            id:            PaddleAdjustmentId::of($sdk->id),
            transactionId: PaddleTransactionId::of($sdk->transactionId),
            customerId:    PaddleCustomerId::of($sdk->customerId),
            action:        AdjustmentAction::from($sdk->action->getValue()),
            status:        AdjustmentStatus::from($sdk->status->getValue()),
            total:         $sdk->totals->total,
            currencyCode:  (string) $sdk->currencyCode,
            reason:        $sdk->reason,
            createdAt:     \DateTimeImmutable::createFromInterface($sdk->createdAt),
        );
    }
}
