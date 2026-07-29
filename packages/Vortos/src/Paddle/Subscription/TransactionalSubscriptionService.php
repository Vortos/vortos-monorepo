<?php

declare(strict_types=1);

namespace Vortos\Paddle\Subscription;

use Vortos\Paddle\Outbox\PaddleOutboxWriterInterface;
use Vortos\Paddle\Subscription\Contract\ImmediateSubscriptionServiceInterface;
use Vortos\Paddle\Subscription\Contract\SubscriptionServiceInterface;
use Vortos\Paddle\Subscription\Operation\CancelSubscriptionRequest;
use Vortos\Paddle\Subscription\Operation\PauseSubscriptionRequest;
use Vortos\Paddle\Subscription\Operation\SubscriptionItemRequest;
use Vortos\Paddle\Subscription\Operation\UpdateSubscriptionRequest;
use Vortos\Paddle\ValueObject\PaddleSubscriptionId;

final class TransactionalSubscriptionService implements SubscriptionServiceInterface
{
    public function __construct(
        private readonly PaddleOutboxWriterInterface          $outbox,
        private readonly ImmediateSubscriptionServiceInterface $reader,
    ) {}

    public function get(PaddleSubscriptionId $id): Subscription
    {
        return $this->reader->get($id);
    }

    public function update(PaddleSubscriptionId $id, UpdateSubscriptionRequest $request): void
    {
        $this->outbox->queue('subscription.update', [
            'id'            => $id->value,
            'prorationMode' => $request->prorationMode?->value,
            'nextBilledAt'  => $request->nextBilledAt,
            // Carried through the outbox because a plan change *is* a change of items.
            // Dropping them here queued an update that reached Paddle saying nothing.
            'items'         => $request->items === null ? null : array_map(
                static fn (SubscriptionItemRequest $item): array => [
                    'priceId'  => $item->priceId->value,
                    'quantity' => $item->quantity,
                ],
                array_values($request->items),
            ),
        ]);
    }

    public function pause(PaddleSubscriptionId $id, ?PauseSubscriptionRequest $request = null): void
    {
        $this->outbox->queue('subscription.pause', ['id' => $id->value]);
    }

    public function resume(PaddleSubscriptionId $id): void
    {
        $this->outbox->queue('subscription.resume', ['id' => $id->value]);
    }

    public function cancel(PaddleSubscriptionId $id, ?CancelSubscriptionRequest $request = null): void
    {
        $this->outbox->queue('subscription.cancel', ['id' => $id->value]);
    }

    public function activate(PaddleSubscriptionId $id): void
    {
        $this->outbox->queue('subscription.activate', ['id' => $id->value]);
    }

    public function previewUpdate(PaddleSubscriptionId $id, UpdateSubscriptionRequest $request): SubscriptionUpdatePreview
    {
        return $this->reader->previewUpdate($id, $request);
    }

    public function list(): array
    {
        return $this->reader->list();
    }
}
