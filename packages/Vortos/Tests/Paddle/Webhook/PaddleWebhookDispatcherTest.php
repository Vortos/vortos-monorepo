<?php

declare(strict_types=1);

namespace Vortos\Tests\Paddle\Webhook;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Vortos\Paddle\Webhook\Event\PaddleWebhookEvent;
use Vortos\Paddle\Webhook\Event\Subscription\SubscriptionCreatedEvent;
use Vortos\Paddle\Webhook\Event\Subscription\SubscriptionCanceledEvent;
use Vortos\Paddle\Webhook\PaddleWebhookDispatcher;
use Vortos\Paddle\Webhook\PaddleWebhookHandlerInterface;

final class PaddleWebhookDispatcherTest extends TestCase
{
    private function makeEvent(string $eventType): PaddleWebhookEvent
    {
        return new SubscriptionCreatedEvent(
            eventId: 'evt_01',
            notificationId: 'ntf_01',
            eventType: $eventType,
            occurredAt: new \DateTimeImmutable(),
            data: [],
        );
    }

    private function makeHandler(string $handles, bool &$called, bool $throws = false): PaddleWebhookHandlerInterface
    {
        return new class($handles, $called, $throws) implements PaddleWebhookHandlerInterface {
            public function __construct(
                private readonly string $eventType,
                public bool &$called,
                private readonly bool $throws,
            ) {}

            public function handles(): string { return $this->eventType; }

            public function handle(PaddleWebhookEvent $event): void
            {
                $this->called = true;
                if ($this->throws) {
                    throw new \RuntimeException('Handler failed');
                }
            }
        };
    }

    public function test_matching_handler_is_called(): void
    {
        $called  = false;
        $handler = $this->makeHandler('subscription.created', $called);

        $dispatcher = new PaddleWebhookDispatcher([$handler], new NullLogger());
        $dispatcher->dispatch($this->makeEvent('subscription.created'));

        $this->assertTrue($called);
    }

    public function test_non_matching_handler_is_not_called(): void
    {
        $called  = false;
        $handler = $this->makeHandler('subscription.canceled', $called);

        $dispatcher = new PaddleWebhookDispatcher([$handler], new NullLogger());
        $dispatcher->dispatch($this->makeEvent('subscription.created'));

        $this->assertFalse($called);
    }

    public function test_multiple_handlers_for_same_event_all_called(): void
    {
        $called1 = false;
        $called2 = false;
        $h1      = $this->makeHandler('subscription.created', $called1);
        $h2      = $this->makeHandler('subscription.created', $called2);

        $dispatcher = new PaddleWebhookDispatcher([$h1, $h2], new NullLogger());
        $dispatcher->dispatch($this->makeEvent('subscription.created'));

        $this->assertTrue($called1);
        $this->assertTrue($called2);
    }

    public function test_one_handler_throwing_does_not_stop_others(): void
    {
        $called1 = false;
        $called2 = false;
        $h1      = $this->makeHandler('subscription.created', $called1, throws: true);
        $h2      = $this->makeHandler('subscription.created', $called2);

        $dispatcher = new PaddleWebhookDispatcher([$h1, $h2], new NullLogger());
        $dispatcher->dispatch($this->makeEvent('subscription.created'));

        $this->assertTrue($called1);
        $this->assertTrue($called2);
    }

    public function test_dispatch_with_no_handlers_does_not_throw(): void
    {
        $dispatcher = new PaddleWebhookDispatcher([], new NullLogger());
        $dispatcher->dispatch($this->makeEvent('subscription.created'));
        $this->assertTrue(true);
    }

    public function test_mixed_event_types_only_relevant_handlers_called(): void
    {
        $createdCalled  = false;
        $canceledCalled = false;
        $h1             = $this->makeHandler('subscription.created', $createdCalled);
        $h2             = $this->makeHandler('subscription.canceled', $canceledCalled);

        $dispatcher = new PaddleWebhookDispatcher([$h1, $h2], new NullLogger());
        $dispatcher->dispatch($this->makeEvent('subscription.created'));

        $this->assertTrue($createdCalled);
        $this->assertFalse($canceledCalled);
    }
}
