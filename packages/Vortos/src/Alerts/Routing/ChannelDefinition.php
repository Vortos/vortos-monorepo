<?php

declare(strict_types=1);

namespace Vortos\Alerts\Routing;

use InvalidArgumentException;

/**
 * Maps a logical channel (`oncall-page`, `eng-chat`) to a notifier driver key +
 * destination config. Destination secrets are referenced by name only
 * (`${env:...}` / `vortos-secrets`), never inlined here.
 */
final readonly class ChannelDefinition
{
    /** @param array<string, string> $destination e.g. ['url_env' => 'ALERTS_SLACK_WEBHOOK_URL'] */
    public function __construct(
        public string $channelKey,
        public string $notifierKey,
        public array $destination = [],
    ) {
        if ($channelKey === '' || $notifierKey === '') {
            throw new InvalidArgumentException('ChannelDefinition channelKey/notifierKey must not be empty.');
        }
    }
}
