<?php

declare(strict_types=1);

namespace Fortizan\Tekton\Messaging\Registry;

/**
 * Runtime registry mapping consumer names and event classes to handler descriptors.
 *
 * Populated by HandlerDiscoveryCompilerPass at container compile time.
 * Each descriptor contains the service ID, method name, and metadata needed
 * by ConsumerRunner to invoke the correct handler for each received message.
 * Handlers are stored sorted by priority descending — highest priority runs first.
 * 
 * Shape: ['consumer_name' => ['EventClass' => [descriptor, descriptor, ...]]]
 * 
 * A descriptor is an array with keys: handlerId (string), 
 * serviceId (string — DI service ID), method (string — method name to call), 
 * priority (int), idempotent (bool), version (?int).
 */
final class HandlerRegistry
{
    private array $handlers = [];

    /**
     * Register a handler descriptor for a consumer + event class combination.
     * Called exclusively by HandlerDiscoveryCompilerPass during container compilation.
     * Maintains priority ordering — descriptors are sorted descending after each insert.
     */
    public function registerHandler(string $consumerName, string $eventClass, array $descriptor):void
    {
        $this->handlers[$consumerName][$eventClass][] = $descriptor;

        usort(
            $this->handlers[$consumerName][$eventClass],
            fn(array $a, array $b) => $b['priority'] <=> $a['priority']
        );
    }

    /**
     * Retrieve all handler descriptors for a given consumer and event class.
     * Returns an empty array if no handlers are registered — not an exception.
     * Results are pre-sorted by priority descending (highest runs first).
     */
    public function getHandlers(string $consumerName, string $eventClass):array
    {
        return $this->handlers[$consumerName][$eventClass] ?? [];
    }

    /** Returns true if at least one handler is registered for this consumer + event class pair. */
    public function hasHandlers(string $consumerName, string $eventClass):bool
    {
        return isset($this->handlers[$consumerName][$eventClass]);
    }

    /** Returns all consumer names that have at least one registered handler. */
    public function allConsumers():array
    {
        return array_keys($this->handlers);
    }

    /**
     * Returns all event class to descriptor mappings for a given consumer.
     * Returns empty array if the consumer has no registered handlers.
     * Used by diagnostic commands to inspect handler registration.
     */
    public function allForConsumer(string $consumerName):array
    {
        return $this->handlers[$consumerName] ?? [];
    }


}