<?php

declare(strict_types=1);

namespace Vortos\Analytics\Transport;

/**
 * The provider-agnostic egress seam: POST a JSON batch to a URL. Mirrors
 * `Observability\Sink\ErrorTransportInterface`. No implementation ships in core —
 * the `null` driver needs none — so core carries no curl/HTTP dependency; a
 * concrete implementation (e.g. `CurlAnalyticsTransport`) ships with the driver
 * split that needs it.
 */
interface AnalyticsTransportInterface
{
    /**
     * Best-effort: returns true on success, false on any failure. Must be bounded
     * (hard timeout) so a hung backend can never stall the caller, and MUST NOT
     * throw.
     *
     * @param array<string,string> $headers
     */
    public function send(string $url, string $jsonBody, array $headers): bool;
}
