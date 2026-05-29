<?php

declare(strict_types=1);

namespace Vortos\Paddle\Checkout;

use Vortos\Paddle\Api\PaddleApiClientInterface;
use Vortos\Paddle\ValueObject\PaddleTransactionId;

final class CheckoutService
{
    public function __construct(private readonly PaddleApiClientInterface $client) {}

    public function createTransactionCheckout(PaddleTransactionId $id): CheckoutUrl
    {
        $sdk = $this->client->call(
            fn() => $this->client->sdk()->transactions->get($id->value)
        );

        $url = $sdk->checkout?->url;

        if ($url === null) {
            throw new \RuntimeException(sprintf('Transaction %s has no checkout URL.', $id->value));
        }

        return new CheckoutUrl($url);
    }

    public function createSubscriptionCheckout(SubscriptionCheckoutRequest $request): CheckoutUrl
    {
        $sdk = $this->client->call(
            fn() => $this->client->sdk()->subscriptions->get($request->subscriptionId->value)
        );

        $url = $sdk->managementUrls?->updatePaymentMethod;

        if ($url === null) {
            throw new \RuntimeException(sprintf(
                'Subscription %s has no payment update URL.',
                $request->subscriptionId->value
            ));
        }

        return new CheckoutUrl($url);
    }
}
