<?php

declare(strict_types=1);

namespace Vortos\Paddle\Subscription;

use Paddle\SDK\Entities\Subscription\SubscriptionEffectiveFrom;
use Paddle\SDK\Entities\Subscription\SubscriptionProrationBillingMode;
use Paddle\SDK\Resources\Subscriptions\Operations\CancelSubscription;
use Paddle\SDK\Resources\Subscriptions\Operations\PauseSubscription;
use Paddle\SDK\Resources\Subscriptions\Operations\PreviewUpdateSubscription;
use Paddle\SDK\Resources\Subscriptions\Operations\ResumeSubscription;
use Paddle\SDK\Resources\Subscriptions\Operations\UpdateSubscription;
use Vortos\Paddle\Api\PaddleApiClientInterface;
use Vortos\Paddle\Subscription\Contract\ImmediateSubscriptionServiceInterface;
use Vortos\Paddle\Subscription\Operation\CancelSubscriptionRequest;
use Vortos\Paddle\Subscription\Operation\PauseSubscriptionRequest;
use Vortos\Paddle\Subscription\Operation\UpdateSubscriptionRequest;
use Vortos\Paddle\ValueObject\PaddleSubscriptionId;

final class ImmediateSubscriptionService implements ImmediateSubscriptionServiceInterface
{
    public function __construct(private readonly PaddleApiClientInterface $client) {}

    public function get(PaddleSubscriptionId $id): Subscription
    {
        $sdk = $this->client->call(
            fn() => $this->client->sdk()->subscriptions->get($id->value)
        );

        return Subscription::fromSdk($sdk);
    }

    public function update(PaddleSubscriptionId $id, UpdateSubscriptionRequest $request): void
    {
        $undef = new \Paddle\SDK\Undefined();

        $this->client->call(
            fn() => $this->client->sdk()->subscriptions->update(
                $id->value,
                new UpdateSubscription(
                    prorationBillingMode: $request->prorationMode !== null
                        ? SubscriptionProrationBillingMode::from($request->prorationMode->value)
                        : $undef,
                )
            )
        );
    }

    public function pause(PaddleSubscriptionId $id, ?PauseSubscriptionRequest $request = null): void
    {
        $effectiveFrom = $request?->effectiveFrom !== null
            ? SubscriptionEffectiveFrom::from('immediately')
            : null;

        $this->client->call(
            fn() => $this->client->sdk()->subscriptions->pause(
                $id->value,
                new PauseSubscription(effectiveFrom: $effectiveFrom)
            )
        );
    }

    public function resume(PaddleSubscriptionId $id): void
    {
        $this->client->call(
            fn() => $this->client->sdk()->subscriptions->resume(
                $id->value,
                new ResumeSubscription()
            )
        );
    }

    public function cancel(PaddleSubscriptionId $id, ?CancelSubscriptionRequest $request = null): void
    {
        $this->client->call(
            fn() => $this->client->sdk()->subscriptions->cancel(
                $id->value,
                new CancelSubscription()
            )
        );
    }

    public function activate(PaddleSubscriptionId $id): void
    {
        $this->client->call(
            fn() => $this->client->sdk()->subscriptions->activate($id->value)
        );
    }

    public function previewUpdate(PaddleSubscriptionId $id, UpdateSubscriptionRequest $request): SubscriptionUpdatePreview
    {
        $undef = new \Paddle\SDK\Undefined();

        $sdkPreview = $this->client->call(
            fn() => $this->client->sdk()->subscriptions->previewUpdate(
                $id->value,
                new PreviewUpdateSubscription(
                    prorationBillingMode: $request->prorationMode !== null
                        ? SubscriptionProrationBillingMode::from($request->prorationMode->value)
                        : $undef,
                )
            )
        );

        $immediateTotal   = $sdkPreview->updateSummary?->result->amount ?? '0';
        $nextBillingTotal = $sdkPreview->nextTransaction?->totals->total ?? '0';

        return new SubscriptionUpdatePreview(
            subscriptionId:   $id,
            immediateTotal:   $immediateTotal,
            nextBillingTotal: $nextBillingTotal,
            currencyCode:     (string) $sdkPreview->currencyCode,
        );
    }

    public function list(): array
    {
        $collection = $this->client->call(
            fn() => $this->client->sdk()->subscriptions->list()
        );

        return array_map(
            fn($sdk) => Subscription::fromSdk($sdk),
            iterator_to_array($collection)
        );
    }
}
