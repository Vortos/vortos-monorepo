<?php

declare(strict_types=1);

namespace Vortos\Push\Contract;

/**
 * A push message: the (already-serialised, typically JSON) payload the service
 * worker will receive, plus delivery hints. `ttl` is how long the push service
 * should retain it for an offline device; `urgency` and `topic` map to the
 * respective Web Push headers (a topic collapses older messages of the same
 * topic still queued for the device).
 */
final class WebPushMessage
{
    public function __construct(
        public readonly string $payload,
        public readonly int $ttl = 2419200,
        public readonly string $urgency = 'normal',
        public readonly ?string $topic = null,
    ) {}
}
