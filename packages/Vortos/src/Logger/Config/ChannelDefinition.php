<?php

declare(strict_types=1);

namespace Vortos\Logger\Config;

use Monolog\Level;

/**
 * A named logger (e.g. "security") and the sinks its records fan out to.
 *
 * Channels are an open registry — apps/packages may register additional
 * channels beyond the framework defaults in LogChannel.
 */
final class ChannelDefinition
{
    /**
     * @param list<string> $sinkIds Sink ids this channel's records are routed to.
     */
    public function __construct(
        public readonly string $name,
        public readonly array $sinkIds,
        public readonly Level $level,
        public readonly bool $disabled = false,
    ) {}
}
