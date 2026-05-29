<?php

declare(strict_types=1);

namespace Vortos\Paddle\Checkout;

final class CheckoutUrl
{
    public function __construct(public readonly string $url) {}

    public function __toString(): string
    {
        return $this->url;
    }
}
