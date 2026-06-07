<?php

declare(strict_types=1);

namespace Vortos\Paddle\Tests\Subscription;

use PHPUnit\Framework\TestCase;
use Vortos\Paddle\Api\PaddleApiClientInterface;
use Vortos\Paddle\Subscription\ImmediateSubscriptionService;
use Vortos\Paddle\Subscription\Subscription;
use Vortos\Paddle\Subscription\SubscriptionUpdatePreview;
use Vortos\Paddle\Subscription\Operation\UpdateSubscriptionRequest;
use Vortos\Paddle\Subscription\Operation\PauseSubscriptionRequest;
use Vortos\Paddle\Subscription\Operation\CancelSubscriptionRequest;
use Vortos\Paddle\ValueObject\PaddleSubscriptionId;
use Vortos\Paddle\ValueObject\ProrationMode;

final class ImmediateSubscriptionServiceTest extends TestCase
{
    private function makeSdkSubscription(string $id = 'sub_test_123', string $status = 'active'): \Paddle\SDK\Entities\Subscription
    {
        return \Paddle\SDK\Entities\Subscription::from([
            'id'              => $id,
            'status'          => $status,
            'customer_id'     => 'ctm_test',
            'address_id'      => 'add_test',
            'business_id'     => null,
            'currency_code'   => 'USD',
            'created_at'      => '2024-01-01T00:00:00.000000Z',
            'updated_at'      => '2024-01-02T00:00:00.000000Z',
            'started_at'      => null,
            'first_billed_at' => null,
            'next_billed_at'  => null,
            'paused_at'       => null,
            'canceled_at'     => null,
            'discount'        => null,
            'collection_mode' => 'automatic',
            'billing_details' => null,
            'current_billing_period' => null,
            'billing_cycle'   => ['interval' => 'month', 'frequency' => 1],
            'scheduled_change' => null,
            'management_urls' => null,
            'items'           => [],
            'custom_data'     => null,
            'import_meta'     => null,
            'next_transaction' => null,
            'recurring_transaction_details' => null,
        ]);
    }

    public function test_get_returns_mapped_subscription(): void
    {
        $client = $this->createMock(PaddleApiClientInterface::class);
        $client->method('call')->willReturn($this->makeSdkSubscription('sub_xyz'));

        $service      = new ImmediateSubscriptionService($client);
        $subscription = $service->get(PaddleSubscriptionId::of('sub_xyz'));

        $this->assertInstanceOf(Subscription::class, $subscription);
        $this->assertSame('sub_xyz', $subscription->id->value);
        $this->assertSame('USD', $subscription->currencyCode);
    }

    public function test_update_delegates_to_sdk(): void
    {
        $client = $this->createMock(PaddleApiClientInterface::class);
        $client->expects($this->once())->method('call');

        $service = new ImmediateSubscriptionService($client);
        $service->update(
            PaddleSubscriptionId::of('sub_123'),
            new UpdateSubscriptionRequest(prorationMode: ProrationMode::ProratedImmediately)
        );
    }

    public function test_pause_delegates_to_sdk(): void
    {
        $client = $this->createMock(PaddleApiClientInterface::class);
        $client->expects($this->once())->method('call');

        $service = new ImmediateSubscriptionService($client);
        $service->pause(PaddleSubscriptionId::of('sub_123'));
    }

    public function test_resume_delegates_to_sdk(): void
    {
        $client = $this->createMock(PaddleApiClientInterface::class);
        $client->expects($this->once())->method('call');

        $service = new ImmediateSubscriptionService($client);
        $service->resume(PaddleSubscriptionId::of('sub_123'));
    }

    public function test_cancel_delegates_to_sdk(): void
    {
        $client = $this->createMock(PaddleApiClientInterface::class);
        $client->expects($this->once())->method('call');

        $service = new ImmediateSubscriptionService($client);
        $service->cancel(PaddleSubscriptionId::of('sub_123'));
    }

    public function test_activate_delegates_to_sdk(): void
    {
        $client = $this->createMock(PaddleApiClientInterface::class);
        $client->expects($this->once())->method('call');

        $service = new ImmediateSubscriptionService($client);
        $service->activate(PaddleSubscriptionId::of('sub_123'));
    }

    public function test_list_returns_array_of_subscriptions(): void
    {
        $collection = new \ArrayIterator([
            $this->makeSdkSubscription('sub_1'),
            $this->makeSdkSubscription('sub_2'),
        ]);

        $client = $this->createMock(PaddleApiClientInterface::class);
        $client->method('call')->willReturn($collection);

        $service       = new ImmediateSubscriptionService($client);
        $subscriptions = $service->list();

        $this->assertCount(2, $subscriptions);
        $this->assertContainsOnlyInstancesOf(Subscription::class, $subscriptions);
    }

    public function test_subscription_status_maps_correctly(): void
    {
        $client = $this->createMock(PaddleApiClientInterface::class);
        $client->method('call')->willReturn($this->makeSdkSubscription('sub_paused', 'paused'));

        $service      = new ImmediateSubscriptionService($client);
        $subscription = $service->get(PaddleSubscriptionId::of('sub_paused'));

        $this->assertSame(\Vortos\Paddle\ValueObject\SubscriptionStatus::Paused, $subscription->status);
    }

    public function test_preview_update_returns_preview(): void
    {
        $sdkPreview = \Paddle\SDK\Entities\SubscriptionPreview::from([
            'status'          => 'active',
            'customer_id'     => 'ctm_test',
            'address_id'      => 'add_test',
            'business_id'     => null,
            'currency_code'   => 'USD',
            'created_at'      => '2024-01-01T00:00:00.000000Z',
            'updated_at'      => '2024-01-02T00:00:00.000000Z',
            'started_at'      => null,
            'first_billed_at' => null,
            'next_billed_at'  => null,
            'paused_at'       => null,
            'canceled_at'     => null,
            'discount'        => null,
            'collection_mode' => 'automatic',
            'billing_details' => null,
            'current_billing_period' => null,
            'billing_cycle'   => ['interval' => 'month', 'frequency' => 1],
            'scheduled_change' => null,
            'management_urls' => ['update_payment_method' => null, 'cancel' => 'https://example.com/cancel'],
            'items'           => [],
            'custom_data'     => null,
            'immediate_transaction' => null,
            'next_transaction' => null,
            'recurring_transaction_details' => null,
            'update_summary'  => null,
        ]);

        $client = $this->createMock(PaddleApiClientInterface::class);
        $client->method('call')->willReturn($sdkPreview);

        $service  = new ImmediateSubscriptionService($client);
        $preview  = $service->previewUpdate(
            PaddleSubscriptionId::of('sub_123'),
            new UpdateSubscriptionRequest(prorationMode: ProrationMode::ProratedImmediately)
        );

        $this->assertInstanceOf(SubscriptionUpdatePreview::class, $preview);
        $this->assertSame('sub_123', $preview->subscriptionId->value);
        $this->assertSame('USD', $preview->currencyCode);
        $this->assertSame('0', $preview->immediateTotal);
        $this->assertSame('0', $preview->nextBillingTotal);
    }
}
