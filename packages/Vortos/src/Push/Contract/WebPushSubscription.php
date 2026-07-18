<?php

declare(strict_types=1);

namespace Vortos\Push\Contract;

/**
 * The transport-level identity of one browser push subscription: the service
 * endpoint URL and the client key material (base64url) used to encrypt the
 * payload per RFC 8291. The consuming application owns storage of these; the
 * package only needs the three values to send.
 */
final class WebPushSubscription
{
    public function __construct(
        public readonly string $endpoint,
        public readonly string $p256dh,
        public readonly string $auth,
    ) {}
}
