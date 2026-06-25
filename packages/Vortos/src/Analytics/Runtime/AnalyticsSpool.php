<?php

declare(strict_types=1);

namespace Vortos\Analytics\Runtime;

use Throwable;
use Vortos\Analytics\Event\AnalyticsEvent;
use Vortos\Observability\Buffer\BoundedSpool;
use Vortos\Observability\Buffer\SpoolStats;

/**
 * A thin, analytics-shaped wrapper over the proven `BoundedSpool` discipline
 * (bounded, crash-safe, drop-oldest) already used for observability errors and
 * deploy markers — durability is opt-in (`ANALYTICS_SPOOL=1`) and the request path
 * never blocks on it: `enqueue()` is O(1) amortized and never throws.
 */
final class AnalyticsSpool
{
    public function __construct(private readonly BoundedSpool $spool) {}

    public function enqueue(AnalyticsEvent $event): bool
    {
        try {
            $payload = json_encode([
                'distinctId' => $event->distinctId->value,
                'name' => $event->name,
                'properties' => $event->properties,
                'timestamp' => $event->timestamp?->getTimestamp(),
                'groups' => $event->groups,
            ], JSON_THROW_ON_ERROR);

            return $this->spool->enqueue($payload);
        } catch (Throwable) {
            return false;
        }
    }

    /** @return list<AnalyticsEvent> */
    public function drain(int $batch): array
    {
        $events = [];
        foreach ($this->spool->drain($batch) as $record) {
            $event = $this->decode($record->payload);
            if ($event !== null) {
                $events[] = $event;
            }
        }

        return $events;
    }

    public function isEmpty(): bool
    {
        return $this->spool->isEmpty();
    }

    public function stats(): SpoolStats
    {
        return $this->spool->stats();
    }

    private function decode(string $payload): ?AnalyticsEvent
    {
        try {
            $data = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($data) ? AnalyticsEvent::fromArray($data) : null;
    }
}
