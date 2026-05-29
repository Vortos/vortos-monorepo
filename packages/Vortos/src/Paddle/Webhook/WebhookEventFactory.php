<?php

declare(strict_types=1);

namespace Vortos\Paddle\Webhook;

use Vortos\Paddle\Webhook\Event\PaddleWebhookEvent;
use Vortos\Paddle\Webhook\Event\UnknownPaddleWebhookEvent;
use Vortos\Paddle\Webhook\Event\Subscription\SubscriptionCreatedEvent;
use Vortos\Paddle\Webhook\Event\Subscription\SubscriptionUpdatedEvent;
use Vortos\Paddle\Webhook\Event\Subscription\SubscriptionActivatedEvent;
use Vortos\Paddle\Webhook\Event\Subscription\SubscriptionPastDueEvent;
use Vortos\Paddle\Webhook\Event\Subscription\SubscriptionPausedEvent;
use Vortos\Paddle\Webhook\Event\Subscription\SubscriptionResumedEvent;
use Vortos\Paddle\Webhook\Event\Subscription\SubscriptionCanceledEvent;
use Vortos\Paddle\Webhook\Event\Subscription\SubscriptionTrialingEvent;
use Vortos\Paddle\Webhook\Event\Subscription\SubscriptionImportedEvent;
use Vortos\Paddle\Webhook\Event\Transaction\TransactionCreatedEvent;
use Vortos\Paddle\Webhook\Event\Transaction\TransactionUpdatedEvent;
use Vortos\Paddle\Webhook\Event\Transaction\TransactionBilledEvent;
use Vortos\Paddle\Webhook\Event\Transaction\TransactionPaymentFailedEvent;
use Vortos\Paddle\Webhook\Event\Transaction\TransactionPaidEvent;
use Vortos\Paddle\Webhook\Event\Transaction\TransactionReadyEvent;
use Vortos\Paddle\Webhook\Event\Transaction\TransactionCompletedEvent;
use Vortos\Paddle\Webhook\Event\Transaction\TransactionCanceledEvent;
use Vortos\Paddle\Webhook\Event\Transaction\TransactionPastDueEvent;
use Vortos\Paddle\Webhook\Event\Transaction\TransactionRevisionCreatedEvent;
use Vortos\Paddle\Webhook\Event\Transaction\TransactionRevisedEvent;
use Vortos\Paddle\Webhook\Event\Transaction\TransactionPaymentMethodSavedEvent;
use Vortos\Paddle\Webhook\Event\Customer\CustomerCreatedEvent;
use Vortos\Paddle\Webhook\Event\Customer\CustomerUpdatedEvent;
use Vortos\Paddle\Webhook\Event\Customer\CustomerImportedEvent;
use Vortos\Paddle\Webhook\Event\Adjustment\AdjustmentCreatedEvent;
use Vortos\Paddle\Webhook\Event\Adjustment\AdjustmentUpdatedEvent;
use Vortos\Paddle\Webhook\Event\PaymentMethod\PaymentMethodSavedEvent;
use Vortos\Paddle\Webhook\Event\PaymentMethod\PaymentMethodDeletedEvent;
use Vortos\Paddle\Webhook\Event\Discount\DiscountCreatedEvent;
use Vortos\Paddle\Webhook\Event\Discount\DiscountUpdatedEvent;
use Vortos\Paddle\Webhook\Event\Discount\DiscountImportedEvent;
use Vortos\Paddle\Webhook\Event\Address\AddressCreatedEvent;
use Vortos\Paddle\Webhook\Event\Address\AddressUpdatedEvent;
use Vortos\Paddle\Webhook\Event\Address\AddressImportedEvent;
use Vortos\Paddle\Webhook\Event\Product\ProductCreatedEvent;
use Vortos\Paddle\Webhook\Event\Product\ProductUpdatedEvent;
use Vortos\Paddle\Webhook\Event\Product\ProductImportedEvent;
use Vortos\Paddle\Webhook\Event\Price\PriceCreatedEvent;
use Vortos\Paddle\Webhook\Event\Price\PriceUpdatedEvent;
use Vortos\Paddle\Webhook\Event\Price\PriceImportedEvent;
use Vortos\Paddle\Webhook\Event\Business\BusinessCreatedEvent;
use Vortos\Paddle\Webhook\Event\Business\BusinessUpdatedEvent;
use Vortos\Paddle\Webhook\Event\Business\BusinessImportedEvent;
use Vortos\Paddle\Webhook\Event\Payout\PayoutCreatedEvent;
use Vortos\Paddle\Webhook\Event\Payout\PayoutPaidEvent;
use Vortos\Paddle\Webhook\Event\ApiKey\ApiKeyCreatedEvent;
use Vortos\Paddle\Webhook\Event\ApiKey\ApiKeyRotatedEvent;
use Vortos\Paddle\Webhook\Event\ApiKey\ApiKeyRevokedEvent;
use Vortos\Paddle\Webhook\Event\ApiKey\ApiKeyPurgedEvent;
use Vortos\Paddle\Webhook\Event\ApiKey\ApiKeyImportedEvent;
use Vortos\Paddle\Webhook\Event\ApiKey\ApiKeyExpiredEvent;
use Vortos\Paddle\Webhook\Event\Report\ReportCreatedEvent;
use Vortos\Paddle\Webhook\Event\Report\ReportUpdatedEvent;

final class WebhookEventFactory
{
    private const TYPE_MAP = [
        'subscription.created'              => SubscriptionCreatedEvent::class,
        'subscription.updated'              => SubscriptionUpdatedEvent::class,
        'subscription.activated'            => SubscriptionActivatedEvent::class,
        'subscription.past_due'             => SubscriptionPastDueEvent::class,
        'subscription.paused'               => SubscriptionPausedEvent::class,
        'subscription.resumed'              => SubscriptionResumedEvent::class,
        'subscription.canceled'             => SubscriptionCanceledEvent::class,
        'subscription.trialing'             => SubscriptionTrialingEvent::class,
        'subscription.imported'             => SubscriptionImportedEvent::class,
        'transaction.created'               => TransactionCreatedEvent::class,
        'transaction.updated'               => TransactionUpdatedEvent::class,
        'transaction.billed'                => TransactionBilledEvent::class,
        'transaction.payment_failed'        => TransactionPaymentFailedEvent::class,
        'transaction.paid'                  => TransactionPaidEvent::class,
        'transaction.ready'                 => TransactionReadyEvent::class,
        'transaction.completed'             => TransactionCompletedEvent::class,
        'transaction.canceled'              => TransactionCanceledEvent::class,
        'transaction.past_due'              => TransactionPastDueEvent::class,
        'transaction.revision_created'      => TransactionRevisionCreatedEvent::class,
        'transaction.revised'               => TransactionRevisedEvent::class,
        'transaction.payment_method_saved'  => TransactionPaymentMethodSavedEvent::class,
        'customer.created'                  => CustomerCreatedEvent::class,
        'customer.updated'                  => CustomerUpdatedEvent::class,
        'customer.imported'                 => CustomerImportedEvent::class,
        'adjustment.created'                => AdjustmentCreatedEvent::class,
        'adjustment.updated'                => AdjustmentUpdatedEvent::class,
        'payment_method.saved'              => PaymentMethodSavedEvent::class,
        'payment_method.deleted'            => PaymentMethodDeletedEvent::class,
        'discount.created'                  => DiscountCreatedEvent::class,
        'discount.updated'                  => DiscountUpdatedEvent::class,
        'discount.imported'                 => DiscountImportedEvent::class,
        'address.created'                   => AddressCreatedEvent::class,
        'address.updated'                   => AddressUpdatedEvent::class,
        'address.imported'                  => AddressImportedEvent::class,
        'product.created'                   => ProductCreatedEvent::class,
        'product.updated'                   => ProductUpdatedEvent::class,
        'product.imported'                  => ProductImportedEvent::class,
        'price.created'                     => PriceCreatedEvent::class,
        'price.updated'                     => PriceUpdatedEvent::class,
        'price.imported'                    => PriceImportedEvent::class,
        'business.created'                  => BusinessCreatedEvent::class,
        'business.updated'                  => BusinessUpdatedEvent::class,
        'business.imported'                 => BusinessImportedEvent::class,
        'payout.created'                    => PayoutCreatedEvent::class,
        'payout.paid'                       => PayoutPaidEvent::class,
        'api_key.created'                   => ApiKeyCreatedEvent::class,
        'api_key.rotated'                   => ApiKeyRotatedEvent::class,
        'api_key.revoked'                   => ApiKeyRevokedEvent::class,
        'api_key.purged'                    => ApiKeyPurgedEvent::class,
        'api_key.imported'                  => ApiKeyImportedEvent::class,
        'api_key.expired'                   => ApiKeyExpiredEvent::class,
        'report.created'                    => ReportCreatedEvent::class,
        'report.updated'                    => ReportUpdatedEvent::class,
    ];

    public function fromVerifiedPayload(array $payload): PaddleWebhookEvent
    {
        $eventId        = $payload['event_id'] ?? '';
        $notificationId = $payload['notification_id'] ?? '';
        $eventType      = $payload['event_type'] ?? '';
        $data           = $payload['data'] ?? [];

        $occurredAt = $this->parseOccurredAt($payload['occurred_at'] ?? '');

        $class = self::TYPE_MAP[$eventType] ?? UnknownPaddleWebhookEvent::class;

        return new $class($eventId, $notificationId, $eventType, $occurredAt, $data);
    }

    private function parseOccurredAt(string $value): \DateTimeImmutable
    {
        if ($value === '') {
            return new \DateTimeImmutable();
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return new \DateTimeImmutable();
        }
    }
}
