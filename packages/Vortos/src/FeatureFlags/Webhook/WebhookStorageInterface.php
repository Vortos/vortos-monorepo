<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Webhook;

interface WebhookStorageInterface
{
    /** @return WebhookSubscription[] */
    public function findActive(): array;

    public function findById(string $id): ?WebhookSubscription;

    public function save(WebhookSubscription $subscription, string $rawSecret): void;

    public function delete(string $id): void;

    /**
     * Record a delivery attempt for auditing / retry.
     */
    public function recordDelivery(WebhookDeliveryRecord $record): void;
}
