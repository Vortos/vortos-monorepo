<?php

declare(strict_types=1);

namespace Vortos\Paddle\Tests\Testing;

use PHPUnit\Framework\TestCase;
use Vortos\Paddle\Testing\FakeSubscriptionService;
use Vortos\Paddle\Subscription\Subscription;
use Vortos\Paddle\Subscription\SubscriptionUpdatePreview;
use Vortos\Paddle\Subscription\Operation\UpdateSubscriptionRequest;
use Vortos\Paddle\ValueObject\PaddleCustomerId;
use Vortos\Paddle\ValueObject\PaddleSubscriptionId;
use Vortos\Paddle\ValueObject\SubscriptionStatus;

final class FakeSubscriptionServiceTest extends TestCase
{
    private function makeSubscription(string $id = 'sub_test'): Subscription
    {
        return new Subscription(
            id:             PaddleSubscriptionId::of($id),
            customerId:     PaddleCustomerId::of('ctm_001'),
            status:         SubscriptionStatus::Active,
            currencyCode:   'USD',
            nextBilledAt:   null,
            pausedAt:       null,
            canceledAt:     null,
            createdAt:      new \DateTimeImmutable('2024-01-01'),
            updatedAt:      new \DateTimeImmutable('2024-01-02'),
        );
    }

    public function test_seed_and_get_returns_subscription(): void
    {
        $fake = new FakeSubscriptionService();
        $sub  = $this->makeSubscription('sub_abc');

        $fake->seed($sub);

        $result = $fake->get(PaddleSubscriptionId::of('sub_abc'));
        $this->assertSame($sub, $result);
    }

    public function test_get_throws_when_not_seeded(): void
    {
        $fake = new FakeSubscriptionService();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('sub_missing');

        $fake->get(PaddleSubscriptionId::of('sub_missing'));
    }

    public function test_list_returns_all_seeded_subscriptions(): void
    {
        $fake = new FakeSubscriptionService();
        $sub1 = $this->makeSubscription('sub_1');
        $sub2 = $this->makeSubscription('sub_2');

        $fake->seed($sub1);
        $fake->seed($sub2);

        $list = $fake->list();
        $this->assertCount(2, $list);
        $this->assertContains($sub1, $list);
        $this->assertContains($sub2, $list);
    }

    public function test_activate_records_and_asserts(): void
    {
        $fake = new FakeSubscriptionService();
        $id   = PaddleSubscriptionId::of('sub_act');

        $fake->activate($id);

        $fake->assertActivated($id);
        $this->addToAssertionCount(1);
    }

    public function test_assert_activated_throws_when_not_activated(): void
    {
        $fake = new FakeSubscriptionService();

        $this->expectException(\AssertionError::class);
        $this->expectExceptionMessage('sub_not_activated');

        $fake->assertActivated(PaddleSubscriptionId::of('sub_not_activated'));
    }

    public function test_pause_records_and_asserts(): void
    {
        $fake = new FakeSubscriptionService();
        $id   = PaddleSubscriptionId::of('sub_paused');

        $fake->pause($id);

        $fake->assertPaused($id);
        $this->addToAssertionCount(1);
    }

    public function test_assert_paused_throws_when_not_paused(): void
    {
        $fake = new FakeSubscriptionService();

        $this->expectException(\AssertionError::class);
        $this->expectExceptionMessage('sub_not_paused');

        $fake->assertPaused(PaddleSubscriptionId::of('sub_not_paused'));
    }

    public function test_resume_removes_from_paused(): void
    {
        $fake = new FakeSubscriptionService();
        $id   = PaddleSubscriptionId::of('sub_resume');

        $fake->pause($id);
        $fake->resume($id);

        $this->expectException(\AssertionError::class);
        $fake->assertPaused($id);
    }

    public function test_cancel_records_and_asserts(): void
    {
        $fake = new FakeSubscriptionService();
        $id   = PaddleSubscriptionId::of('sub_canceled');

        $fake->cancel($id);

        $fake->assertCanceled($id);
        $this->addToAssertionCount(1);
    }

    public function test_assert_canceled_throws_when_not_canceled(): void
    {
        $fake = new FakeSubscriptionService();

        $this->expectException(\AssertionError::class);
        $this->expectExceptionMessage('sub_not_canceled');

        $fake->assertCanceled(PaddleSubscriptionId::of('sub_not_canceled'));
    }

    public function test_preview_update_returns_zero_totals(): void
    {
        $fake    = new FakeSubscriptionService();
        $id      = PaddleSubscriptionId::of('sub_preview');
        $request = new UpdateSubscriptionRequest();

        $preview = $fake->previewUpdate($id, $request);

        $this->assertInstanceOf(SubscriptionUpdatePreview::class, $preview);
        $this->assertSame('0', $preview->immediateTotal);
        $this->assertSame('0', $preview->nextBillingTotal);
        $this->assertSame('USD', $preview->currencyCode);
    }

    public function test_update_is_noop(): void
    {
        $fake    = new FakeSubscriptionService();
        $id      = PaddleSubscriptionId::of('sub_update');
        $request = new UpdateSubscriptionRequest();

        // Should not throw
        $fake->update($id, $request);
        $this->assertTrue(true);
    }
}
