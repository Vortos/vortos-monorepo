<?php

declare(strict_types=1);

namespace Vortos\Paddle\Subscription;

use Paddle\SDK\Entities\Subscription\SubscriptionEffectiveFrom;
use Paddle\SDK\Entities\Subscription\SubscriptionProrationBillingMode;
use Paddle\SDK\Resources\Subscriptions\Operations\CancelSubscription;
use Paddle\SDK\Resources\Subscriptions\Operations\PauseSubscription;
use Paddle\SDK\Resources\Subscriptions\Operations\PreviewUpdateSubscription;
use Paddle\SDK\Resources\Subscriptions\Operations\ResumeSubscription;
use Paddle\SDK\Resources\Subscriptions\Operations\Update\SubscriptionUpdateItem;
use Paddle\SDK\Resources\Subscriptions\Operations\UpdateSubscription;
use Vortos\Paddle\Api\PaddleApiClientInterface;
use Vortos\Paddle\Subscription\Contract\ImmediateSubscriptionServiceInterface;
use Vortos\Paddle\Subscription\Operation\CancelSubscriptionRequest;
use Vortos\Paddle\Subscription\Operation\PauseSubscriptionRequest;
use Vortos\Paddle\Subscription\Operation\SubscriptionItemRequest;
use Vortos\Paddle\Subscription\Operation\UpdateSubscriptionRequest;
use Vortos\Paddle\ValueObject\PaddleSubscriptionId;
use Vortos\Paddle\ValueObject\ProrationAction;

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
        $this->client->call(
            fn() => $this->client->sdk()->subscriptions->update(
                $id->value,
                new UpdateSubscription(
                    nextBilledAt:         $this->nextBilledAt($request),
                    items:                $this->items($request),
                    prorationBillingMode: $this->prorationMode($request),
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
        $sdkPreview = $this->client->call(
            fn() => $this->client->sdk()->subscriptions->previewUpdate(
                $id->value,
                new PreviewUpdateSubscription(
                    nextBilledAt:         $this->nextBilledAt($request),
                    items:                $this->items($request),
                    prorationBillingMode: $this->prorationMode($request),
                )
            )
        );

        $summary        = $sdkPreview->updateSummary;
        $immediateTotal = $summary !== null ? $summary->result->amount : '0';

        // `->details->totals`, not `->totals`. The latter does not exist on this
        // entity, so it read as null and fell through to '0' — quoting every
        // customer a next invoice of nothing, however much they were about to owe.
        $nextTransaction  = $sdkPreview->nextTransaction;
        $nextBillingTotal = '0';
        if ($nextTransaction !== null) {
            $totals           = $nextTransaction->details->totals;
            $nextBillingTotal = $totals->grandTotal ?? $totals->total;
        }

        // Where a credit actually goes. Paddle does not refund it to the card: it is
        // added to the customer's balance and drawn down by later invoices, which is
        // the first thing anyone downgrading asks.
        $immediate       = $sdkPreview->immediateTransaction;
        $creditToBalance = $immediate !== null
            ? ($immediate->details->totals->creditToBalance ?? '0')
            : '0';

        // Paddle reports the amount unsigned and the direction separately. Losing the
        // direction here would make a credit indistinguishable from a charge for the
        // same sum, which is the difference between money owed and money returned.
        // No summary means nothing settles now, and nothing is the same either way.
        $action = $summary !== null
            ? (ProrationAction::tryFrom((string) $summary->result->action->getValue())
                ?? ProrationAction::Charge)
            : ProrationAction::Charge;

        return new SubscriptionUpdatePreview(
            subscriptionId:   $id,
            immediateTotal:   $immediateTotal,
            nextBillingTotal: $nextBillingTotal,
            currencyCode:     (string) $sdkPreview->currencyCode,
            immediateAction:  $action,
            creditToBalance:  $creditToBalance,
        );
    }

    /**
     * The prices the subscription should carry after the update.
     *
     * This is how a plan change is expressed to Paddle: the subscription keeps its
     * identity and its billing dates, and the items underneath it are replaced. Absent
     * means "leave the items alone", which is what an update that only moves the
     * proration mode or the next billing date wants.
     *
     * @return array<int, SubscriptionUpdateItem>|\Paddle\SDK\Undefined
     */
    private function items(UpdateSubscriptionRequest $request): array|\Paddle\SDK\Undefined
    {
        if ($request->items === null) {
            return new \Paddle\SDK\Undefined();
        }

        return array_map(
            static fn (SubscriptionItemRequest $item): SubscriptionUpdateItem => new SubscriptionUpdateItem(
                priceId:  $item->priceId->value,
                quantity: $item->quantity,
            ),
            array_values($request->items),
        );
    }

    private function nextBilledAt(UpdateSubscriptionRequest $request): \DateTimeInterface|\Paddle\SDK\Undefined
    {
        if ($request->nextBilledAt === null || $request->nextBilledAt === '') {
            return new \Paddle\SDK\Undefined();
        }

        return new \DateTimeImmutable($request->nextBilledAt);
    }

    private function prorationMode(
        UpdateSubscriptionRequest $request,
    ): SubscriptionProrationBillingMode|\Paddle\SDK\Undefined {
        return $request->prorationMode !== null
            ? SubscriptionProrationBillingMode::from($request->prorationMode->value)
            : new \Paddle\SDK\Undefined();
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
