<?php

namespace Fortizan\Tekton\Messenger\EventListener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;

class StopWorkerOnSignalSubscriber implements EventSubscriberInterface
{
    private bool $shouldStop = false;

    public function onWorkerStarted(): void
    {
        // Only register if pcntl is available
        if (!function_exists('pcntl_signal')) {
            return;
        }

        // Register the signal handler for SIGTERM (Deploy) and SIGINT (Ctrl+C)
        pcntl_signal(SIGTERM, function () {
            $this->shouldStop = true;
        });
        pcntl_signal(SIGINT, function () {
            $this->shouldStop = true;
        });
    }

    public function onWorkerRunning(WorkerRunningEvent $event): void
    {
        // 1. Dispatch any pending signals (async check)
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }

        // 2. If signal received, stop the worker cleanly
        if ($this->shouldStop) {
            $event->getWorker()->stop();
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerStartedEvent::class => 'onWorkerStarted',
            WorkerRunningEvent::class => 'onWorkerRunning',
        ];
    }
}