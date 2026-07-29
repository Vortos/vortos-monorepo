<?php

declare(strict_types=1);

namespace Vortos\Paddle\Subscription;

use Vortos\Paddle\ValueObject\PaddleCustomerId;
use Vortos\Paddle\ValueObject\PaddleSubscriptionId;
use Vortos\Paddle\ValueObject\SubscriptionStatus;

final class Subscription
{
    public function __construct(
        public readonly PaddleSubscriptionId $id,
        public readonly PaddleCustomerId     $customerId,
        public readonly SubscriptionStatus   $status,
        public readonly string               $currencyCode,
        public readonly ?\DateTimeImmutable   $nextBilledAt,
        public readonly ?\DateTimeImmutable   $pausedAt,
        public readonly ?\DateTimeImmutable   $canceledAt,
        public readonly \DateTimeImmutable    $createdAt,
        public readonly \DateTimeImmutable    $updatedAt,
        /**
         * The priced lines this subscription bills, which is where the plan and the
         * billing cycle actually live. Defaulted so existing constructor calls keep
         * working; `fromSdk` always populates it.
         *
         * @var list<SubscriptionItem>
         */
        public readonly array                $items = [],
    ) {}

    public static function fromSdk(\Paddle\SDK\Entities\Subscription $sdk): self
    {
        return new self(
            id:           PaddleSubscriptionId::of($sdk->id),
            customerId:   PaddleCustomerId::of($sdk->customerId),
            status:       SubscriptionStatus::from($sdk->status->getValue()),
            currencyCode: (string) $sdk->currencyCode,
            nextBilledAt: $sdk->nextBilledAt !== null
                              ? \DateTimeImmutable::createFromInterface($sdk->nextBilledAt)
                              : null,
            pausedAt:     $sdk->pausedAt !== null
                              ? \DateTimeImmutable::createFromInterface($sdk->pausedAt)
                              : null,
            canceledAt:   $sdk->canceledAt !== null
                              ? \DateTimeImmutable::createFromInterface($sdk->canceledAt)
                              : null,
            createdAt:    \DateTimeImmutable::createFromInterface($sdk->createdAt),
            updatedAt:    \DateTimeImmutable::createFromInterface($sdk->updatedAt),
            items:        array_map(
                static fn (\Paddle\SDK\Entities\Subscription\SubscriptionItem $item): SubscriptionItem
                    => SubscriptionItem::fromSdk($item),
                array_values($sdk->items),
            ),
        );
    }
}
