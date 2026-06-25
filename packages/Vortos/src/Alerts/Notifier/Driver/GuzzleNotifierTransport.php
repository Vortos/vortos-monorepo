<?php

declare(strict_types=1);

namespace Vortos\Alerts\Notifier\Driver;

use GuzzleHttp\ClientInterface;
use Throwable;

/**
 * The single place every in-core notifier driver's HTTP call goes through. Bounded
 * (hard timeout), redirect-following disabled (SSRF defense in depth — a same-host
 * allow-listed URL must not be able to redirect to a denied target), never throws.
 */
final class GuzzleNotifierTransport implements HttpNotifierTransportInterface
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly float $timeoutSeconds = 5.0,
    ) {}

    public function send(string $url, array $payload, array $headers = []): bool
    {
        try {
            $response = $this->client->request('POST', $url, [
                'json' => $payload,
                'headers' => $headers,
                'timeout' => $this->timeoutSeconds,
                'connect_timeout' => min(2.0, $this->timeoutSeconds),
                'allow_redirects' => false,
            ]);
        } catch (Throwable) {
            return false;
        }

        $status = $response->getStatusCode();

        return $status >= 200 && $status < 300;
    }
}
