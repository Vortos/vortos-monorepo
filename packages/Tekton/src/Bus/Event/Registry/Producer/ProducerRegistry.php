<?php

namespace Fortizan\Tekton\Bus\Event\Registry\Producer;

class ProducerRegistry
{
    /**
     * Structure: 
     * [
     * 'App\Events\UserCreated' => [
     * 'audit' => EventMetadata(...),
     * 'metrics' => EventMetadata(...)
     * ]
     * ]
     * * @param array<string, array<string, EventMetadata>> $map
     */
    public function __construct(
        private array $map = []
    ) {}

    /**
     * @return EventMetadata[] Returns all routes for this event
     */
    public function getRoutes(string $eventClass): array
    {
        // Return empty array if not registered (maybe it's a local event?)
        // Or throw exception if you want strict enforcement.
        return $this->map[$eventClass] ?? throw new \RuntimeException("Event '{$eventClass}' is not registered.");
    }

    /**
     * Helper to get metadata for a specific channel (used by Consumers)
     */
    public function getRouteForChannel(string $eventClass, string $channel): ?EventMetadata
    {
        return $this->map[$eventClass][$channel] ?? null;
    }
}