<?php

declare(strict_types=1);

namespace Vortos\Paddle\Testing;

use Vortos\Paddle\Subscription\Contract\StandaloneSubscriptionServiceInterface;
use Vortos\Paddle\Subscription\Subscription;
use Vortos\Paddle\Subscription\SubscriptionUpdatePreview;
use Vortos\Paddle\Subscription\Operation\CancelSubscriptionRequest;
use Vortos\Paddle\Subscription\Operation\PauseSubscriptionRequest;
use Vortos\Paddle\Subscription\Operation\UpdateSubscriptionRequest;
use Vortos\Paddle\ValueObject\PaddleCustomerId;
use Vortos\Paddle\ValueObject\PaddleSubscriptionId;
use Vortos\Paddle\ValueObject\SubscriptionStatus;

final class FakeSubscriptionService implements StandaloneSubscriptionServiceInterface
{
    /** @var array<string, Subscription> */
    private array $subscriptions = [];

    /** @var string[] */
    private array $activated = [];

    /** @var string[] */
    private array $paused = [];

    /** @var string[] */
    private array $canceled = [];

    public function seed(Subscription $subscription): void
    {
        $this->subscriptions[$subscription->id->value] = $subscription;
    }

    public function get(PaddleSubscriptionId $id): Subscription
    {
        if (!isset($this->subscriptions[$id->value])) {
            throw new \RuntimeException("Fake subscription not found: {$id->value}");
        }

        return $this->subscriptions[$id->value];
    }

    public function update(PaddleSubscriptionId $id, UpdateSubscriptionRequest $request): void
    {
        // no-op in fake
    }

    public function pause(PaddleSubscriptionId $id, ?PauseSubscriptionRequest $request = null): void
    {
        $this->paused[] = $id->value;
    }

    public function resume(PaddleSubscriptionId $id): void
    {
        $this->paused = array_filter($this->paused, fn($v) => $v !== $id->value);
    }

    public function cancel(PaddleSubscriptionId $id, ?CancelSubscriptionRequest $request = null): void
    {
        $this->canceled[] = $id->value;
    }

    public function activate(PaddleSubscriptionId $id): void
    {
        $this->activated[] = $id->value;
    }

    public function previewUpdate(PaddleSubscriptionId $id, UpdateSubscriptionRequest $request): SubscriptionUpdatePreview
    {
        return new SubscriptionUpdatePreview(
            subscriptionId:   $id,
            immediateTotal:   '0',
            nextBillingTotal: '0',
            currencyCode:     'USD',
        );
    }

    /** @return Subscription[] */
    public function list(): array
    {
        return array_values($this->subscriptions);
    }

    public function assertActivated(PaddleSubscriptionId $id): void
    {
        if (!in_array($id->value, $this->activated, true)) {
            throw new \AssertionError("Expected subscription {$id->value} to be activated, but it was not.");
        }
    }

    public function assertCanceled(PaddleSubscriptionId $id): void
    {
        if (!in_array($id->value, $this->canceled, true)) {
            throw new \AssertionError("Expected subscription {$id->value} to be canceled, but it was not.");
        }
    }

    public function assertPaused(PaddleSubscriptionId $id): void
    {
        if (!in_array($id->value, $this->paused, true)) {
            throw new \AssertionError("Expected subscription {$id->value} to be paused, but it was not.");
        }
    }
}
