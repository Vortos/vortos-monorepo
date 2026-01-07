<?php

use Fortizan\Tekton\Messenger\EventListener\StopWorkerOnSignalSubscriber;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Messenger\EventListener\StopWorkerOnRestartSignalListener;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMemoryLimitListener;
use Symfony\Component\Messenger\EventListener\DispatchPcntlSignalListener;


use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $configurator) {
    $services = $configurator->services();

    // ===== Signal Handling =====

    $services->set(StopWorkerOnSignalSubscriber::class)
        ->tag('kernel.event_subscriber');

    $services->set(DispatchPcntlSignalListener::class)
        ->tag('kernel.event_subscriber');

    $services->set(StopWorkerOnRestartSignalListener::class)
        ->args([service('cache.app')]) 
        ->tag('kernel.event_subscriber');

    // ===== Memory Management =====

    $services->set(StopWorkerOnMemoryLimitListener::class)
        ->args([
            128 * 1024 * 1024,
            null,            
            null          
        ])
        ->tag('kernel.event_subscriber');

    // ===== Optional: Time Limits =====

    // Uncomment to stop worker after X seconds (good for cron-style workers)
    $services->set(\Symfony\Component\Messenger\EventListener\StopWorkerOnTimeLimitListener::class)
        ->args([3600]) // Stop after 1 hour
        ->tag('kernel.event_subscriber');

    // ===== Optional: Message Limits =====

    // Uncomment to stop worker after X messages (prevents long-running issues)
    $services->set(\Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener::class)
        ->args([1000]) // Stop after 1000 messages
        ->tag('kernel.event_subscriber');

    // ===== Optional: Failure Limits =====

    // Uncomment to stop worker after X failures (circuit breaker pattern)
    $services->set(\Symfony\Component\Messenger\EventListener\StopWorkerOnCustomStopExceptionListener::class)
        ->tag('kernel.event_subscriber');
};
