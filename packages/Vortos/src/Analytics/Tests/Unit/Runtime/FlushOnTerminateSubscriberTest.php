<?php

declare(strict_types=1);

namespace Vortos\Analytics\Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Vortos\Analytics\AnalyticsInterface;
use Vortos\Analytics\Capability\AnalyticsCapability;
use Vortos\Analytics\Event\AnalyticsEvent;
use Vortos\Analytics\Event\GroupAssociation;
use Vortos\Analytics\Event\IdentitySet;
use Vortos\Analytics\Runtime\FlushOnTerminateSubscriber;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

final class FlushOnTerminateSubscriberTest extends TestCase
{
    public function test_on_terminate_flushes_analytics(): void
    {
        $analytics = new class implements AnalyticsInterface {
            public int $flushCount = 0;

            public function name(): string { return 'spy'; }
            public function capture(AnalyticsEvent $event): void {}
            public function identify(IdentitySet $identity): void {}
            public function group(GroupAssociation $group): void {}
            public function flush(): void { $this->flushCount++; }
            public function capabilities(): CapabilityDescriptor
            {
                return CapabilityDescriptor::create([AnalyticsCapability::Batching->value => true]);
            }
        };

        $subscriber = new FlushOnTerminateSubscriber($analytics);
        $subscriber->onTerminate($this->terminateEvent());

        $this->assertSame(1, $analytics->flushCount);
    }

    public function test_on_terminate_never_throws_when_flush_throws(): void
    {
        $analytics = new class implements AnalyticsInterface {
            public function name(): string { return 'spy'; }
            public function capture(AnalyticsEvent $event): void {}
            public function identify(IdentitySet $identity): void {}
            public function group(GroupAssociation $group): void {}
            public function flush(): void { throw new RuntimeException('boom'); }
            public function capabilities(): CapabilityDescriptor
            {
                return CapabilityDescriptor::create([AnalyticsCapability::Batching->value => false]);
            }
        };

        $subscriber = new FlushOnTerminateSubscriber($analytics);
        $subscriber->onTerminate($this->terminateEvent());

        $this->addToAssertionCount(1);
    }

    public function test_subscribes_to_kernel_terminate(): void
    {
        $this->assertArrayHasKey(
            \Symfony\Component\HttpKernel\KernelEvents::TERMINATE,
            FlushOnTerminateSubscriber::getSubscribedEvents(),
        );
    }

    private function terminateEvent(): TerminateEvent
    {
        $kernel = $this->createMock(HttpKernelInterface::class);

        return new TerminateEvent($kernel, Request::create('/'), new Response());
    }
}
