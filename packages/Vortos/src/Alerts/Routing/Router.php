<?php

declare(strict_types=1);

namespace Vortos\Alerts\Routing;

use Vortos\Alerts\Event\AlertEvent;

/** Pure: `AlertEvent → list<RoutedDelivery>`; honors per-rule routingOverride and per-tenant routing. */
final class Router
{
    public function __construct(
        private readonly RoutingMatrix $matrix,
        private readonly ChannelRegistry $channels,
    ) {}

    /**
     * @param list<string>|null $routingOverride explicit channel keys from the firing AlertRule
     * @return list<RoutedDelivery>
     */
    public function route(AlertEvent $event, ?array $routingOverride = null): array
    {
        $channelKeys = $routingOverride ?? $this->matrix->channelsFor($event->severity, $event->source, $event->tenantId);

        $deliveries = [];
        foreach ($channelKeys as $channelKey) {
            $channel = $this->channels->get($channelKey);
            $deliveries[] = new RoutedDelivery($channel->channelKey, $channel->notifierKey);
        }

        return $deliveries;
    }
}
