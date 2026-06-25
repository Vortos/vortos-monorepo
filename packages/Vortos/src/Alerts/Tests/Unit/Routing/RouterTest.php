<?php

declare(strict_types=1);

namespace Vortos\Alerts\Tests\Unit\Routing;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Vortos\Alerts\Event\AlertEvent;
use Vortos\Alerts\Event\AlertSource;
use Vortos\Alerts\Routing\ChannelDefinition;
use Vortos\Alerts\Routing\ChannelRegistry;
use Vortos\Alerts\Routing\RoutingMatrix;
use Vortos\Alerts\Routing\Router;
use Vortos\Alerts\Severity;

final class RouterTest extends TestCase
{
    private function router(): Router
    {
        $channels = new ChannelRegistry([
            new ChannelDefinition('eng-chat', 'telegram'),
            new ChannelDefinition('oncall-page', 'telegram'),
        ]);

        return new Router(RoutingMatrix::default(), $channels);
    }

    private function event(Severity $severity): AlertEvent
    {
        return AlertEvent::scrubbed(
            ruleId: 'r1',
            severity: $severity,
            title: 't',
            summary: 's',
            source: AlertSource::Health,
            env: 'prod',
            tenantId: null,
            labels: [],
            annotations: [],
            links: [],
            occurredAt: new DateTimeImmutable(),
        );
    }

    public function test_critical_routes_to_page_and_chat(): void
    {
        $deliveries = $this->router()->route($this->event(Severity::Critical));

        self::assertCount(2, $deliveries);
        self::assertSame('oncall-page', $deliveries[0]->channelKey);
        self::assertSame('eng-chat', $deliveries[1]->channelKey);
    }

    public function test_routing_override_bypasses_matrix(): void
    {
        $deliveries = $this->router()->route($this->event(Severity::Info), ['oncall-page']);

        self::assertCount(1, $deliveries);
        self::assertSame('oncall-page', $deliveries[0]->channelKey);
    }
}
