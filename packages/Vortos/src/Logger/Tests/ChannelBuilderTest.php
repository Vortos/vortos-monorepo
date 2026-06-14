<?php

declare(strict_types=1);

namespace Vortos\Logger\Tests;

use Monolog\Level;
use PHPUnit\Framework\TestCase;
use Vortos\Logger\DependencyInjection\ChannelBuilder;

final class ChannelBuilderTest extends TestCase
{
    public function test_default_sink_ids_is_the_channel_name(): void
    {
        $channel = new ChannelBuilder('app');

        $this->assertSame(['app'], $channel->sinkIds('app'));
        $this->assertFalse($channel->hasRouting());
    }

    public function test_route_to_replaces_default_routing(): void
    {
        $channel = new ChannelBuilder('security');
        $channel->routeTo('siem');

        $this->assertSame(['siem'], $channel->sinkIds('security'));
        $this->assertTrue($channel->hasRouting());
    }

    public function test_also_route_to_appends_to_default_routing(): void
    {
        $channel = new ChannelBuilder('security');
        $channel->alsoRouteTo('siem');

        $this->assertSame(['security', 'siem'], $channel->sinkIds('security'));
        $this->assertTrue($channel->hasRouting());
    }

    public function test_also_route_to_appends_to_explicit_routing(): void
    {
        $channel = new ChannelBuilder('security');
        $channel->routeTo('primary')->alsoRouteTo('siem');

        $this->assertSame(['primary', 'siem'], $channel->sinkIds('security'));
    }

    public function test_also_route_to_deduplicates(): void
    {
        $channel = new ChannelBuilder('security');
        $channel->alsoRouteTo('siem', 'siem');

        $this->assertSame(['security', 'siem'], $channel->sinkIds('security'));
    }

    public function test_sink_ids_deduplicates_against_default(): void
    {
        $channel = new ChannelBuilder('security');
        $channel->alsoRouteTo('security');

        $this->assertSame(['security'], $channel->sinkIds('security'));
    }

    public function test_level_and_get_level(): void
    {
        $channel = new ChannelBuilder('app');

        $this->assertNull($channel->getLevel());

        $channel->level(Level::Warning);
        $this->assertSame(Level::Warning, $channel->getLevel());
    }

    public function test_disable_and_is_disabled(): void
    {
        $channel = new ChannelBuilder('cache');

        $this->assertFalse($channel->isDisabled());

        $channel->disable();
        $this->assertTrue($channel->isDisabled());
    }

    public function test_name(): void
    {
        $channel = new ChannelBuilder('http');

        $this->assertSame('http', $channel->name());
    }

    public function test_fluent_interface_returns_same_instance(): void
    {
        $channel = new ChannelBuilder('app');

        $this->assertSame($channel, $channel->routeTo('a'));
        $this->assertSame($channel, $channel->alsoRouteTo('b'));
        $this->assertSame($channel, $channel->level(Level::Error));
        $this->assertSame($channel, $channel->disable());
    }
}
