<?php

declare(strict_types=1);

namespace Vortos\Tests\Paddle\Webhook;

use PHPUnit\Framework\TestCase;
use Vortos\Paddle\Webhook\Event\Adjustment\AdjustmentCreatedEvent;
use Vortos\Paddle\Webhook\Event\Adjustment\AdjustmentUpdatedEvent;
use Vortos\Paddle\Webhook\Event\Address\AddressCreatedEvent;
use Vortos\Paddle\Webhook\Event\Address\AddressUpdatedEvent;
use Vortos\Paddle\Webhook\Event\Address\AddressImportedEvent;
use Vortos\Paddle\Webhook\Event\ApiKey\ApiKeyCreatedEvent;
use Vortos\Paddle\Webhook\Event\ApiKey\ApiKeyExpiredEvent;
use Vortos\Paddle\Webhook\Event\ApiKey\ApiKeyImportedEvent;
use Vortos\Paddle\Webhook\Event\ApiKey\ApiKeyPurgedEvent;
use Vortos\Paddle\Webhook\Event\ApiKey\ApiKeyRevokedEvent;
use Vortos\Paddle\Webhook\Event\ApiKey\ApiKeyRotatedEvent;
use Vortos\Paddle\Webhook\Event\Business\BusinessCreatedEvent;
use Vortos\Paddle\Webhook\Event\Business\BusinessUpdatedEvent;
use Vortos\Paddle\Webhook\Event\Business\BusinessImportedEvent;
use Vortos\Paddle\Webhook\Event\Customer\CustomerCreatedEvent;
use Vortos\Paddle\Webhook\Event\Customer\CustomerUpdatedEvent;
use Vortos\Paddle\Webhook\Event\Customer\CustomerImportedEvent;
use Vortos\Paddle\Webhook\Event\Discount\DiscountCreatedEvent;
use Vortos\Paddle\Webhook\Event\Discount\DiscountUpdatedEvent;
use Vortos\Paddle\Webhook\Event\Discount\DiscountImportedEvent;
use Vortos\Paddle\Webhook\Event\PaymentMethod\PaymentMethodSavedEvent;
use Vortos\Paddle\Webhook\Event\PaymentMethod\PaymentMethodDeletedEvent;
use Vortos\Paddle\Webhook\Event\Payout\PayoutCreatedEvent;
use Vortos\Paddle\Webhook\Event\Payout\PayoutPaidEvent;
use Vortos\Paddle\Webhook\Event\Price\PriceCreatedEvent;
use Vortos\Paddle\Webhook\Event\Price\PriceUpdatedEvent;
use Vortos\Paddle\Webhook\Event\Price\PriceImportedEvent;
use Vortos\Paddle\Webhook\Event\Product\ProductCreatedEvent;
use Vortos\Paddle\Webhook\Event\Product\ProductUpdatedEvent;
use Vortos\Paddle\Webhook\Event\Product\ProductImportedEvent;
use Vortos\Paddle\Webhook\Event\Report\ReportCreatedEvent;
use Vortos\Paddle\Webhook\Event\Report\ReportUpdatedEvent;
use Vortos\Paddle\Webhook\Event\Subscription\SubscriptionActivatedEvent;
use Vortos\Paddle\Webhook\Event\Subscription\SubscriptionCanceledEvent;
use Vortos\Paddle\Webhook\Event\Subscription\SubscriptionCreatedEvent;
use Vortos\Paddle\Webhook\Event\Subscription\SubscriptionImportedEvent;
use Vortos\Paddle\Webhook\Event\Subscription\SubscriptionPastDueEvent;
use Vortos\Paddle\Webhook\Event\Subscription\SubscriptionPausedEvent;
use Vortos\Paddle\Webhook\Event\Subscription\SubscriptionResumedEvent;
use Vortos\Paddle\Webhook\Event\Subscription\SubscriptionTrialingEvent;
use Vortos\Paddle\Webhook\Event\Subscription\SubscriptionUpdatedEvent;
use Vortos\Paddle\Webhook\Event\Transaction\TransactionBilledEvent;
use Vortos\Paddle\Webhook\Event\Transaction\TransactionCanceledEvent;
use Vortos\Paddle\Webhook\Event\Transaction\TransactionCompletedEvent;
use Vortos\Paddle\Webhook\Event\Transaction\TransactionCreatedEvent;
use Vortos\Paddle\Webhook\Event\Transaction\TransactionPaidEvent;
use Vortos\Paddle\Webhook\Event\Transaction\TransactionPastDueEvent;
use Vortos\Paddle\Webhook\Event\Transaction\TransactionPaymentFailedEvent;
use Vortos\Paddle\Webhook\Event\Transaction\TransactionPaymentMethodSavedEvent;
use Vortos\Paddle\Webhook\Event\Transaction\TransactionReadyEvent;
use Vortos\Paddle\Webhook\Event\Transaction\TransactionRevisionCreatedEvent;
use Vortos\Paddle\Webhook\Event\Transaction\TransactionRevisedEvent;
use Vortos\Paddle\Webhook\Event\Transaction\TransactionUpdatedEvent;
use Vortos\Paddle\Webhook\Event\UnknownPaddleWebhookEvent;
use Vortos\Paddle\Webhook\WebhookEventFactory;

final class WebhookEventFactoryTest extends TestCase
{
    private WebhookEventFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new WebhookEventFactory();
    }

    private function payload(string $eventType, string $eventId = 'evt_01'): array
    {
        return [
            'event_id'        => $eventId,
            'notification_id' => 'ntf_01',
            'event_type'      => $eventType,
            'occurred_at'     => '2024-06-01T12:00:00.000000Z',
            'data'            => ['id' => 'sub_01'],
        ];
    }

    /** @dataProvider eventTypeProvider */
    public function test_known_event_type_produces_correct_class(string $eventType, string $expectedClass): void
    {
        $event = $this->factory->fromVerifiedPayload($this->payload($eventType));
        $this->assertInstanceOf($expectedClass, $event);
        $this->assertSame($eventType, $event->eventType);
        $this->assertSame('evt_01', $event->eventId);
        $this->assertSame('ntf_01', $event->notificationId);
        $this->assertSame(['id' => 'sub_01'], $event->data);
    }

    public static function eventTypeProvider(): array
    {
        return [
            ['subscription.created',             SubscriptionCreatedEvent::class],
            ['subscription.updated',             SubscriptionUpdatedEvent::class],
            ['subscription.activated',           SubscriptionActivatedEvent::class],
            ['subscription.past_due',            SubscriptionPastDueEvent::class],
            ['subscription.paused',              SubscriptionPausedEvent::class],
            ['subscription.resumed',             SubscriptionResumedEvent::class],
            ['subscription.canceled',            SubscriptionCanceledEvent::class],
            ['subscription.trialing',            SubscriptionTrialingEvent::class],
            ['subscription.imported',            SubscriptionImportedEvent::class],
            ['transaction.created',              TransactionCreatedEvent::class],
            ['transaction.updated',              TransactionUpdatedEvent::class],
            ['transaction.billed',               TransactionBilledEvent::class],
            ['transaction.payment_failed',       TransactionPaymentFailedEvent::class],
            ['transaction.paid',                 TransactionPaidEvent::class],
            ['transaction.ready',                TransactionReadyEvent::class],
            ['transaction.completed',            TransactionCompletedEvent::class],
            ['transaction.canceled',             TransactionCanceledEvent::class],
            ['transaction.past_due',             TransactionPastDueEvent::class],
            ['transaction.revision_created',     TransactionRevisionCreatedEvent::class],
            ['transaction.revised',              TransactionRevisedEvent::class],
            ['transaction.payment_method_saved', TransactionPaymentMethodSavedEvent::class],
            ['customer.created',                 CustomerCreatedEvent::class],
            ['customer.updated',                 CustomerUpdatedEvent::class],
            ['customer.imported',                CustomerImportedEvent::class],
            ['adjustment.created',               AdjustmentCreatedEvent::class],
            ['adjustment.updated',               AdjustmentUpdatedEvent::class],
            ['payment_method.saved',             PaymentMethodSavedEvent::class],
            ['payment_method.deleted',           PaymentMethodDeletedEvent::class],
            ['discount.created',                 DiscountCreatedEvent::class],
            ['discount.updated',                 DiscountUpdatedEvent::class],
            ['discount.imported',                DiscountImportedEvent::class],
            ['address.created',                  AddressCreatedEvent::class],
            ['address.updated',                  AddressUpdatedEvent::class],
            ['address.imported',                 AddressImportedEvent::class],
            ['product.created',                  ProductCreatedEvent::class],
            ['product.updated',                  ProductUpdatedEvent::class],
            ['product.imported',                 ProductImportedEvent::class],
            ['price.created',                    PriceCreatedEvent::class],
            ['price.updated',                    PriceUpdatedEvent::class],
            ['price.imported',                   PriceImportedEvent::class],
            ['business.created',                 BusinessCreatedEvent::class],
            ['business.updated',                 BusinessUpdatedEvent::class],
            ['business.imported',                BusinessImportedEvent::class],
            ['payout.created',                   PayoutCreatedEvent::class],
            ['payout.paid',                      PayoutPaidEvent::class],
            ['api_key.created',                  ApiKeyCreatedEvent::class],
            ['api_key.rotated',                  ApiKeyRotatedEvent::class],
            ['api_key.revoked',                  ApiKeyRevokedEvent::class],
            ['api_key.purged',                   ApiKeyPurgedEvent::class],
            ['api_key.imported',                 ApiKeyImportedEvent::class],
            ['api_key.expired',                  ApiKeyExpiredEvent::class],
            ['report.created',                   ReportCreatedEvent::class],
            ['report.updated',                   ReportUpdatedEvent::class],
        ];
    }

    public function test_unknown_event_type_produces_unknown_event(): void
    {
        $event = $this->factory->fromVerifiedPayload($this->payload('future.unknown_event'));
        $this->assertInstanceOf(UnknownPaddleWebhookEvent::class, $event);
        $this->assertSame('future.unknown_event', $event->eventType);
    }

    public function test_occurred_at_is_parsed_correctly(): void
    {
        $event = $this->factory->fromVerifiedPayload($this->payload('subscription.created'));
        $this->assertSame('2024-06-01', $event->occurredAt->format('Y-m-d'));
    }

    public function test_missing_occurred_at_defaults_gracefully(): void
    {
        $payload = $this->payload('subscription.created');
        unset($payload['occurred_at']);
        $event = $this->factory->fromVerifiedPayload($payload);
        $this->assertInstanceOf(\DateTimeImmutable::class, $event->occurredAt);
    }

    public function test_empty_data_defaults_to_empty_array(): void
    {
        $payload = $this->payload('subscription.created');
        unset($payload['data']);
        $event = $this->factory->fromVerifiedPayload($payload);
        $this->assertSame([], $event->data);
    }
}
