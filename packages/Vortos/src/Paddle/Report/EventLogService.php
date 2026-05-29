<?php

declare(strict_types=1);

namespace Vortos\Paddle\Report;

use Paddle\SDK\Resources\Events\Operations\ListEvents;
use Vortos\Paddle\Api\PaddleApiClientInterface;
use Vortos\Paddle\Report\Operation\EventLogFilters;

final class EventLogService
{
    public function __construct(private readonly PaddleApiClientInterface $client) {}

    public function listEvents(EventLogFilters $filters): EventLogPage
    {
        $collection = $this->client->call(
            fn() => $this->client->sdk()->events->list(new ListEvents())
        );

        $entries = [];
        foreach ($collection as $sdkEvent) {
            $entries[] = new EventLogEntry(
                eventId:    $sdkEvent->eventId,
                eventType:  $sdkEvent->eventType->getValue(),
                occurredAt: \DateTimeImmutable::createFromInterface($sdkEvent->occurredAt),
            );
        }

        return new EventLogPage(
            entries:    $entries,
            nextCursor: $filters->after,
            hasMore:    false,
        );
    }
}
