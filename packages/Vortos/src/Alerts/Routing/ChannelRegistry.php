<?php

declare(strict_types=1);

namespace Vortos\Alerts\Routing;

use InvalidArgumentException;

/** Declared logical-channel → driver-key + destination map (§3.4). */
final class ChannelRegistry
{
    /** @var array<string, ChannelDefinition> */
    private array $channels = [];

    /** @param list<ChannelDefinition> $channels */
    public function __construct(array $channels = [])
    {
        foreach ($channels as $channel) {
            $this->add($channel);
        }
    }

    public function add(ChannelDefinition $channel): void
    {
        if (isset($this->channels[$channel->channelKey])) {
            throw new InvalidArgumentException(sprintf('Duplicate channel key "%s".', $channel->channelKey));
        }

        $this->channels[$channel->channelKey] = $channel;
    }

    public function has(string $channelKey): bool
    {
        return isset($this->channels[$channelKey]);
    }

    public function get(string $channelKey): ChannelDefinition
    {
        return $this->channels[$channelKey] ?? throw new InvalidArgumentException(sprintf('Unknown channel "%s".', $channelKey));
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->channels);
    }
}
