<?php

declare(strict_types=1);

namespace Vortos\Observability\Driver\Glitchtip;

use Vortos\Observability\Sink\CapturedError;
use Vortos\Observability\Sink\ErrorTransportInterface;

/**
 * Bounded HTTP transport for the error sink, using PHP's curl extension (no new
 * dependency, staying in-core per §14.2). Hard connect + total timeouts so a hung
 * backend can never stall the drain worker.
 *
 * The payload is the scrubbed {@see CapturedError::toArray()} as JSON — the backend
 * ingest format (Sentry/GlitchTip envelope) is a driver-internal detail and can be
 * refined without touching the port.
 */
final class CurlErrorTransport implements ErrorTransportInterface
{
    public function __construct(
        private readonly int $connectTimeoutSeconds = 2,
        private readonly int $totalTimeoutSeconds = 5,
    ) {}

    public function send(string $ingestUrl, CapturedError $error): bool
    {
        if (!function_exists('curl_init')) {
            return false;
        }

        $handle = curl_init();
        if ($handle === false) {
            return false;
        }

        $body = json_encode($error->toArray(), JSON_THROW_ON_ERROR);

        curl_setopt_array($handle, [
            CURLOPT_URL => $ingestUrl,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSeconds,
            CURLOPT_TIMEOUT => $this->totalTimeoutSeconds,
            CURLOPT_FAILONERROR => true,
        ]);

        try {
            $ok = curl_exec($handle) !== false;
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

            return $ok && $status >= 200 && $status < 300;
        } finally {
            curl_close($handle);
        }
    }
}
