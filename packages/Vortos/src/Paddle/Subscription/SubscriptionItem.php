<?php

declare(strict_types=1);

namespace Vortos\Paddle\Subscription;

use Vortos\Paddle\ValueObject\PaddlePriceId;
use Vortos\Paddle\ValueObject\PaddleProductId;

/**
 * One priced line on a subscription.
 *
 * The price id is the part callers actually reason about: it is what says which plan
 * and which billing cycle a subscription is currently on, and there is nowhere else
 * to read that from. Without it, "is this subscription already on the plan the
 * customer just asked for" is unanswerable, and a no-op change gets billed.
 */
final readonly class SubscriptionItem
{
    public function __construct(
        public PaddlePriceId    $priceId,
        public PaddleProductId  $productId,
        public int              $quantity,
        public string           $status,
        public bool             $recurring,
    ) {}

    public static function fromSdk(\Paddle\SDK\Entities\Subscription\SubscriptionItem $sdk): self
    {
        return new self(
            priceId:   PaddlePriceId::of((string) $sdk->price->id),
            productId: PaddleProductId::of((string) $sdk->price->productId),
            quantity:  $sdk->quantity,
            status:    $sdk->status->getValue(),
            recurring: $sdk->recurring,
        );
    }
}
