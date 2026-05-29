<?php

declare(strict_types=1);

namespace Vortos\Paddle\Checkout;

use Vortos\Paddle\ValueObject\PaddleCustomerId;

final class PortalSession
{
    public function __construct(
        public readonly string             $id,
        public readonly PaddleCustomerId   $customerId,
        public readonly string             $url,
        public readonly \DateTimeImmutable $createdAt,
    ) {}

    public static function fromSdk(\Paddle\SDK\Entities\CustomerPortalSession $sdk): self
    {
        return new self(
            id:         $sdk->id,
            customerId: PaddleCustomerId::of($sdk->customerId),
            url:        $sdk->urls->general->overview,
            createdAt:  \DateTimeImmutable::createFromInterface($sdk->createdAt),
        );
    }
}
