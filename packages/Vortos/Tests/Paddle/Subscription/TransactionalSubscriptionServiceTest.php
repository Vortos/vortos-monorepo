<?php

declare(strict_types=1);

namespace Vortos\Tests\Paddle\Subscription;

use PHPUnit\Framework\TestCase;
use Vortos\Paddle\Outbox\PaddleOutboxWriterInterface;
use Vortos\Paddle\Subscription\Contract\ImmediateSubscriptionServiceInterface;
use Vortos\Paddle\Subscription\Subscription;
use Vortos\Paddle\Subscription\SubscriptionUpdatePreview;
use Vortos\Paddle\Subscription\TransactionalSubscriptionService;
use Vortos\Paddle\Subscription\Operation\CancelSubscriptionRequest;
use Vortos\Paddle\Subscription\Operation\PauseSubscriptionRequest;
use Vortos\Paddle\Subscription\Operation\UpdateSubscriptionRequest;
use Vortos\Paddle\ValueObject\CustomerStatus;
use Vortos\Paddle\ValueObject\PaddleCustomerId;
use Vortos\Paddle\ValueObject\PaddleSubscriptionId;
use Vortos\Paddle\ValueObject\ProrationMode;
use Vortos\Paddle\ValueObject\SubscriptionStatus;

final class TransactionalSubscriptionServiceTest extends TestCase
{
    private function makeSubscription(string $id = 'sub_test'): Subscription
    {
        return new Subscription(
            id:           PaddleSubscriptionId::of($id),
            customerId:   PaddleCustomerId::of('ctm_test'),
            status:       SubscriptionStatus::Active,
            currencyCode: 'USD',
            nextBilledAt: null,
            pausedAt:     null,
            canceledAt:   null,
            createdAt:    new \DateTimeImmutable('2024-01-01'),
            updatedAt:    new \DateTimeImmutable('2024-01-02'),
        );
    }

    public function test_get_delegates_to_reader(): void
    {
        $outbox = $this->createMock(PaddleOutboxWriterInterface::class);
        $reader = $this->createMock(ImmediateSubscriptionServiceInterface::class);
        $reader->expects($this->once())
               ->method('get')
               ->willReturn($this->makeSubscription('sub_abc'));

        $service      = new TransactionalSubscriptionService($outbox, $reader);
        $subscription = $service->get(PaddleSubscriptionId::of('sub_abc'));

        $this->assertInstanceOf(Subscription::class, $subscription);
        $this->assertSame('sub_abc', $subscription->id->value);
    }

    public function test_update_queues_to_outbox(): void
    {
        $outbox = $this->createMock(PaddleOutboxWriterInterface::class);
        $outbox->expects($this->once())
               ->method('queue')
               ->with('subscription.update', $this->arrayHasKey('id'));

        $reader  = $this->createMock(ImmediateSubscriptionServiceInterface::class);
        $service = new TransactionalSubscriptionService($outbox, $reader);
        $service->update(
            PaddleSubscriptionId::of('sub_123'),
            new UpdateSubscriptionRequest(prorationMode: ProrationMode::ProratedImmediately)
        );
    }

    public function test_pause_queues_to_outbox(): void
    {
        $outbox = $this->createMock(PaddleOutboxWriterInterface::class);
        $outbox->expects($this->once())
               ->method('queue')
               ->with('subscription.pause', ['id' => 'sub_123']);

        $reader  = $this->createMock(ImmediateSubscriptionServiceInterface::class);
        $service = new TransactionalSubscriptionService($outbox, $reader);
        $service->pause(PaddleSubscriptionId::of('sub_123'));
    }

    public function test_resume_queues_to_outbox(): void
    {
        $outbox = $this->createMock(PaddleOutboxWriterInterface::class);
        $outbox->expects($this->once())
               ->method('queue')
               ->with('subscription.resume', ['id' => 'sub_123']);

        $reader  = $this->createMock(ImmediateSubscriptionServiceInterface::class);
        $service = new TransactionalSubscriptionService($outbox, $reader);
        $service->resume(PaddleSubscriptionId::of('sub_123'));
    }

    public function test_cancel_queues_to_outbox(): void
    {
        $outbox = $this->createMock(PaddleOutboxWriterInterface::class);
        $outbox->expects($this->once())
               ->method('queue')
               ->with('subscription.cancel', ['id' => 'sub_123']);

        $reader  = $this->createMock(ImmediateSubscriptionServiceInterface::class);
        $service = new TransactionalSubscriptionService($outbox, $reader);
        $service->cancel(PaddleSubscriptionId::of('sub_123'));
    }

    public function test_activate_queues_to_outbox(): void
    {
        $outbox = $this->createMock(PaddleOutboxWriterInterface::class);
        $outbox->expects($this->once())
               ->method('queue')
               ->with('subscription.activate', ['id' => 'sub_123']);

        $reader  = $this->createMock(ImmediateSubscriptionServiceInterface::class);
        $service = new TransactionalSubscriptionService($outbox, $reader);
        $service->activate(PaddleSubscriptionId::of('sub_123'));
    }

    public function test_preview_update_delegates_to_reader(): void
    {
        $fakePreview = new SubscriptionUpdatePreview(
            subscriptionId:   PaddleSubscriptionId::of('sub_123'),
            immediateTotal:   '100',
            nextBillingTotal: '200',
            currencyCode:     'USD',
        );

        $outbox = $this->createMock(PaddleOutboxWriterInterface::class);
        $reader = $this->createMock(ImmediateSubscriptionServiceInterface::class);
        $reader->expects($this->once())
               ->method('previewUpdate')
               ->willReturn($fakePreview);

        $service = new TransactionalSubscriptionService($outbox, $reader);
        $preview = $service->previewUpdate(
            PaddleSubscriptionId::of('sub_123'),
            new UpdateSubscriptionRequest(prorationMode: ProrationMode::ProratedImmediately)
        );

        $this->assertSame('100', $preview->immediateTotal);
        $this->assertSame('200', $preview->nextBillingTotal);
    }

    public function test_list_delegates_to_reader(): void
    {
        $fakeSubscriptions = [$this->makeSubscription('sub_1'), $this->makeSubscription('sub_2')];

        $outbox = $this->createMock(PaddleOutboxWriterInterface::class);
        $reader = $this->createMock(ImmediateSubscriptionServiceInterface::class);
        $reader->expects($this->once())
               ->method('list')
               ->willReturn($fakeSubscriptions);

        $service       = new TransactionalSubscriptionService($outbox, $reader);
        $subscriptions = $service->list();

        $this->assertCount(2, $subscriptions);
        $this->assertContainsOnlyInstancesOf(Subscription::class, $subscriptions);
    }
}
