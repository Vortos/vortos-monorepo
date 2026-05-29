<?php

declare(strict_types=1);

namespace Vortos\Paddle\Subscription;

use Doctrine\DBAL\Connection;
use Vortos\Paddle\Subscription\Contract\StandaloneSubscriptionServiceInterface;
use Vortos\Paddle\Subscription\Contract\SubscriptionServiceInterface;
use Vortos\Paddle\Subscription\Operation\CancelSubscriptionRequest;
use Vortos\Paddle\Subscription\Operation\PauseSubscriptionRequest;
use Vortos\Paddle\Subscription\Operation\UpdateSubscriptionRequest;
use Vortos\Paddle\ValueObject\PaddleSubscriptionId;

final class StandaloneSubscriptionService implements StandaloneSubscriptionServiceInterface
{
    public function __construct(
        private readonly Connection                  $connection,
        private readonly SubscriptionServiceInterface $transactional,
    ) {}

    public function get(PaddleSubscriptionId $id): Subscription
    {
        return $this->transactional->get($id);
    }

    public function update(PaddleSubscriptionId $id, UpdateSubscriptionRequest $request): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->transactional->update($id, $request);
            return;
        }

        $this->connection->transactional(
            fn() => $this->transactional->update($id, $request)
        );
    }

    public function pause(PaddleSubscriptionId $id, ?PauseSubscriptionRequest $request = null): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->transactional->pause($id, $request);
            return;
        }

        $this->connection->transactional(
            fn() => $this->transactional->pause($id, $request)
        );
    }

    public function resume(PaddleSubscriptionId $id): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->transactional->resume($id);
            return;
        }

        $this->connection->transactional(
            fn() => $this->transactional->resume($id)
        );
    }

    public function cancel(PaddleSubscriptionId $id, ?CancelSubscriptionRequest $request = null): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->transactional->cancel($id, $request);
            return;
        }

        $this->connection->transactional(
            fn() => $this->transactional->cancel($id, $request)
        );
    }

    public function activate(PaddleSubscriptionId $id): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->transactional->activate($id);
            return;
        }

        $this->connection->transactional(
            fn() => $this->transactional->activate($id)
        );
    }

    public function previewUpdate(PaddleSubscriptionId $id, UpdateSubscriptionRequest $request): SubscriptionUpdatePreview
    {
        return $this->transactional->previewUpdate($id, $request);
    }

    public function list(): array
    {
        return $this->transactional->list();
    }
}
