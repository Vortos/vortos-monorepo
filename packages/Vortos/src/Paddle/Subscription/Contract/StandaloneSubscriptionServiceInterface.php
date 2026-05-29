<?php

declare(strict_types=1);

namespace Vortos\Paddle\Subscription\Contract;

use Vortos\Paddle\Subscription\Operation\CancelSubscriptionRequest;
use Vortos\Paddle\Subscription\Operation\PauseSubscriptionRequest;
use Vortos\Paddle\Subscription\Operation\UpdateSubscriptionRequest;
use Vortos\Paddle\Subscription\Subscription;
use Vortos\Paddle\Subscription\SubscriptionUpdatePreview;
use Vortos\Paddle\ValueObject\PaddleSubscriptionId;

interface StandaloneSubscriptionServiceInterface
{
    public function get(PaddleSubscriptionId $id): Subscription;

    public function update(PaddleSubscriptionId $id, UpdateSubscriptionRequest $request): void;

    public function pause(PaddleSubscriptionId $id, ?PauseSubscriptionRequest $request = null): void;

    public function resume(PaddleSubscriptionId $id): void;

    public function cancel(PaddleSubscriptionId $id, ?CancelSubscriptionRequest $request = null): void;

    public function activate(PaddleSubscriptionId $id): void;

    public function previewUpdate(PaddleSubscriptionId $id, UpdateSubscriptionRequest $request): SubscriptionUpdatePreview;

    /** @return Subscription[] */
    public function list(): array;
}
