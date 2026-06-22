<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\Webhook;

use PHPUnit\Framework\TestCase;
use Vortos\FeatureFlags\Webhook\SsrfGuard;
use Vortos\FeatureFlags\Webhook\WebhookDeliveryInterface;
use Vortos\FeatureFlags\Webhook\WebhookDispatcher;
use Vortos\FeatureFlags\Webhook\WebhookPayload;
use Vortos\FeatureFlags\Webhook\WebhookStorageInterface;
use Vortos\FeatureFlags\Webhook\WebhookSubscription;

final class WebhookDispatcherTest extends TestCase
{
    public function test_dispatches_to_matching_subscriptions(): void
    {
        $sub1 = $this->sub(id: 's1', eventTypes: ['flag.enabled']);
        $sub2 = $this->sub(id: 's2', eventTypes: ['flag.disabled']);

        $storage = $this->createMock(WebhookStorageInterface::class);
        $storage->method('findActive')->willReturn([$sub1, $sub2]);

        $delivery = $this->createMock(WebhookDeliveryInterface::class);
        $delivery->expects($this->once())->method('deliver')
            ->with(
                $this->callback(fn(WebhookPayload $p) => $p->eventType === 'flag.enabled' && $p->subscriptionId === 's1'),
                $sub1,
                $this->anything(),
            );

        $ssrf = new SsrfGuard();

        $dispatcher = new WebhookDispatcher($storage, $delivery, $ssrf);
        $count      = $dispatcher->dispatch('flag.enabled', ['flag' => 'my-flag']);

        $this->assertSame(1, $count);
    }

    public function test_ssrf_blocked_url_is_not_delivered(): void
    {
        $sub = $this->sub(url: 'https://169.254.169.254/hook');

        $storage = $this->createMock(WebhookStorageInterface::class);
        $storage->method('findActive')->willReturn([$sub]);

        $delivery = $this->createMock(WebhookDeliveryInterface::class);
        $delivery->expects($this->never())->method('deliver');

        $ssrf = new SsrfGuard();

        $dispatcher = new WebhookDispatcher($storage, $delivery, $ssrf);
        $count      = $dispatcher->dispatch('flag.enabled', ['flag' => 'my-flag']);

        $this->assertSame(0, $count);
    }

    public function test_inactive_subscription_is_skipped(): void
    {
        $sub = new WebhookSubscription(
            id: 's1', url: 'https://example.com/hook', secretHash: 'h',
            eventTypes: ['*'], active: false,
        );

        $storage = $this->createMock(WebhookStorageInterface::class);
        $storage->method('findActive')->willReturn([$sub]);

        $delivery = $this->createMock(WebhookDeliveryInterface::class);
        $delivery->expects($this->never())->method('deliver');

        $ssrf = new SsrfGuard();

        $dispatcher = new WebhookDispatcher($storage, $delivery, $ssrf);
        $dispatcher->dispatch('flag.enabled', []);
    }

    public function test_project_scoped_subscription_only_receives_matching_events(): void
    {
        $sub = $this->sub(id: 's1', projectId: 'project-a');

        $storage = $this->createMock(WebhookStorageInterface::class);
        $storage->method('findActive')->willReturn([$sub]);

        $delivery = $this->createMock(WebhookDeliveryInterface::class);
        $delivery->expects($this->never())->method('deliver');

        $ssrf = new SsrfGuard();

        $dispatcher = new WebhookDispatcher($storage, $delivery, $ssrf);
        $dispatcher->dispatch('flag.enabled', [], 'project-b');
    }

    public function test_dispatches_multiple_matching_subscriptions(): void
    {
        $sub1 = $this->sub(id: 's1');
        $sub2 = $this->sub(id: 's2');

        $storage = $this->createMock(WebhookStorageInterface::class);
        $storage->method('findActive')->willReturn([$sub1, $sub2]);

        $delivery = $this->createMock(WebhookDeliveryInterface::class);
        $delivery->expects($this->exactly(2))->method('deliver');

        $ssrf = new SsrfGuard();

        $dispatcher = new WebhookDispatcher($storage, $delivery, $ssrf);
        $count      = $dispatcher->dispatch('flag.enabled', []);

        $this->assertSame(2, $count);
    }

    public function test_no_subscriptions_returns_zero(): void
    {
        $storage = $this->createMock(WebhookStorageInterface::class);
        $storage->method('findActive')->willReturn([]);

        $delivery = $this->createMock(WebhookDeliveryInterface::class);
        $ssrf     = new SsrfGuard();

        $dispatcher = new WebhookDispatcher($storage, $delivery, $ssrf);
        $this->assertSame(0, $dispatcher->dispatch('flag.enabled', []));
    }

    private function sub(
        string $id = 's1',
        string $url = 'https://1.1.1.1/webhook',
        array $eventTypes = ['*'],
        ?string $projectId = null,
    ): WebhookSubscription {
        return new WebhookSubscription(
            id: $id, url: $url, secretHash: hash('sha256', 'secret'),
            eventTypes: $eventTypes, projectId: $projectId,
        );
    }
}
