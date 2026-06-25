<?php

declare(strict_types=1);

namespace Vortos\Alerts\Notifier\Driver;

/**
 * Deliver one rendered payload to a backend's HTTP endpoint. Returns true on success.
 * Implementations must be bounded (hard timeout) and must not throw — a transport
 * failure is the driver's signal to return {@see \Vortos\Alerts\Notifier\NotificationResult::failed()}.
 */
interface HttpNotifierTransportInterface
{
    /**
     * @param array<string, mixed>  $payload JSON-encodable request body
     * @param array<string, string> $headers extra request headers
     */
    public function send(string $url, array $payload, array $headers = []): bool;
}
