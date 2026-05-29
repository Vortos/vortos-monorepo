<?php

declare(strict_types=1);

namespace Vortos\Tests\Paddle\Subscription;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Vortos\Paddle\Subscription\Contract\SubscriptionServiceInterface;
use Vortos\Paddle\Subscription\StandaloneSubscriptionService;
use Vortos\Paddle\Subscription\Subscription;
use Vortos\Paddle\Subscription\Operation\UpdateSubscriptionRequest;
use Vortos\Paddle\ValueObject\PaddleCustomerId;
use Vortos\Paddle\ValueObject\PaddleSubscriptionId;
use Vortos\Paddle\ValueObject\ProrationMode;
use Vortos\Paddle\ValueObject\SubscriptionStatus;

final class StandaloneSubscriptionServiceTest extends TestCase
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

    public function test_update_wraps_in_transaction_when_not_active(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(false);
        $connection->expects($this->once())->method('transactional');

        $transactional = $this->createMock(SubscriptionServiceInterface::class);
        $service       = new StandaloneSubscriptionService($connection, $transactional);
        $service->update(
            PaddleSubscriptionId::of('sub_123'),
            new UpdateSubscriptionRequest(prorationMode: ProrationMode::ProratedImmediately)
        );
    }

    public function test_update_delegates_directly_when_transaction_active(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->expects($this->never())->method('transactional');

        $transactional = $this->createMock(SubscriptionServiceInterface::class);
        $transactional->expects($this->once())->method('update');

        $service = new StandaloneSubscriptionService($connection, $transactional);
        $service->update(
            PaddleSubscriptionId::of('sub_123'),
            new UpdateSubscriptionRequest(prorationMode: ProrationMode::ProratedImmediately)
        );
    }

    public function test_pause_wraps_in_transaction_when_not_active(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(false);
        $connection->expects($this->once())->method('transactional');

        $transactional = $this->createMock(SubscriptionServiceInterface::class);
        $service       = new StandaloneSubscriptionService($connection, $transactional);
        $service->pause(PaddleSubscriptionId::of('sub_123'));
    }

    public function test_resume_wraps_in_transaction_when_not_active(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(false);
        $connection->expects($this->once())->method('transactional');

        $transactional = $this->createMock(SubscriptionServiceInterface::class);
        $service       = new StandaloneSubscriptionService($connection, $transactional);
        $service->resume(PaddleSubscriptionId::of('sub_123'));
    }

    public function test_cancel_wraps_in_transaction_when_not_active(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(false);
        $connection->expects($this->once())->method('transactional');

        $transactional = $this->createMock(SubscriptionServiceInterface::class);
        $service       = new StandaloneSubscriptionService($connection, $transactional);
        $service->cancel(PaddleSubscriptionId::of('sub_123'));
    }

    public function test_activate_wraps_in_transaction_when_not_active(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(false);
        $connection->expects($this->once())->method('transactional');

        $transactional = $this->createMock(SubscriptionServiceInterface::class);
        $service       = new StandaloneSubscriptionService($connection, $transactional);
        $service->activate(PaddleSubscriptionId::of('sub_123'));
    }

    public function test_get_delegates_to_transactional(): void
    {
        $connection    = $this->createMock(Connection::class);
        $transactional = $this->createMock(SubscriptionServiceInterface::class);
        $transactional->expects($this->once())
                      ->method('get')
                      ->willReturn($this->makeSubscription('sub_xyz'));

        $service      = new StandaloneSubscriptionService($connection, $transactional);
        $subscription = $service->get(PaddleSubscriptionId::of('sub_xyz'));

        $this->assertSame('sub_xyz', $subscription->id->value);
    }

    public function test_list_delegates_to_transactional(): void
    {
        $connection    = $this->createMock(Connection::class);
        $transactional = $this->createMock(SubscriptionServiceInterface::class);
        $transactional->expects($this->once())
                      ->method('list')
                      ->willReturn([$this->makeSubscription('sub_1'), $this->makeSubscription('sub_2')]);

        $service       = new StandaloneSubscriptionService($connection, $transactional);
        $subscriptions = $service->list();

        $this->assertCount(2, $subscriptions);
        $this->assertContainsOnlyInstancesOf(Subscription::class, $subscriptions);
    }
}
