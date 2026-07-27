<?php

declare(strict_types=1);

namespace Vortos\Paddle\Transaction;

use Vortos\Paddle\ValueObject\PaddleCustomerId;
use Vortos\Paddle\ValueObject\PaddleSubscriptionId;
use Vortos\Paddle\ValueObject\PaddleTransactionId;
use Vortos\Paddle\ValueObject\TransactionStatus;

final class Transaction
{
    public function __construct(
        public readonly PaddleTransactionId  $id,
        public readonly ?PaddleCustomerId    $customerId,
        public readonly ?PaddleSubscriptionId $subscriptionId,
        public readonly TransactionStatus    $status,
        public readonly string               $currencyCode,
        public readonly string               $total,
        public readonly ?\DateTimeImmutable   $billedAt,
        public readonly \DateTimeImmutable    $createdAt,
        public readonly \DateTimeImmutable    $updatedAt,
        /** @var TransactionLineItem[] */
        public readonly array                $lineItems,
        /**
         * Whatever the caller attached when creating the transaction, echoed back.
         * Webhooks already carry it; reading a transaction had been dropping it, so
         * anyone reconciling a payment out-of-band lost the context that said what
         * the payment was for.
         *
         * @var array<string, mixed>
         */
        public readonly array                $customData = [],
    ) {}

    public static function fromSdk(\Paddle\SDK\Entities\Transaction $sdk): self
    {
        return new self(
            id:             PaddleTransactionId::of($sdk->id),
            customerId:     $sdk->customerId !== null ? PaddleCustomerId::of($sdk->customerId) : null,
            subscriptionId: $sdk->subscriptionId !== null ? PaddleSubscriptionId::of($sdk->subscriptionId) : null,
            status:         TransactionStatus::from($sdk->status->getValue()),
            currencyCode:   (string) $sdk->currencyCode,
            total:          $sdk->details->totals->total,
            billedAt:       $sdk->billedAt !== null
                                ? \DateTimeImmutable::createFromInterface($sdk->billedAt)
                                : null,
            createdAt:      \DateTimeImmutable::createFromInterface($sdk->createdAt),
            updatedAt:      \DateTimeImmutable::createFromInterface($sdk->updatedAt),
            lineItems:      array_map(
                fn($item) => TransactionLineItem::fromSdk($item),
                $sdk->details->lineItems
            ),
            customData:     self::customDataFromSdk($sdk),
        );
    }

    /**
     * The SDK models custom data as an object wrapping either an array or anything
     * JsonSerializable, so a transaction created elsewhere can carry a shape we did
     * not write. Only a string-keyed array is usable as custom data; anything else
     * reads as absent rather than becoming a surprise for the caller.
     *
     * @return array<string, mixed>
     */
    private static function customDataFromSdk(\Paddle\SDK\Entities\Transaction $sdk): array
    {
        $data = $sdk->customData?->data;
        if ($data instanceof \JsonSerializable) {
            $data = $data->jsonSerialize();
        }

        return is_array($data) ? $data : [];
    }
}
