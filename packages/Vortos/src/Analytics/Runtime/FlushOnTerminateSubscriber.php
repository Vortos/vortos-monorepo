<?php

declare(strict_types=1);

namespace Vortos\Analytics\Runtime;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;
use Vortos\Analytics\AnalyticsInterface;

/**
 * Flushes any buffered analytics on process shutdown (`kernel.terminate`), so a
 * request that never reaches the {@see BatchingAnalytics} threshold still ships its
 * buffered events rather than losing them when the worker recycles.
 */
final class FlushOnTerminateSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly AnalyticsInterface $analytics) {}

    public function onTerminate(TerminateEvent $event): void
    {
        try {
            $this->analytics->flush();
        } catch (Throwable) {
            // Intentionally swallowed: shutdown flush must never throw.
        }
    }

    /** @return array<string,string> */
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::TERMINATE => 'onTerminate'];
    }
}
