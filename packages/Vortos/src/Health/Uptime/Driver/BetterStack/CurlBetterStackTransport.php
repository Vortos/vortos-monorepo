<?php

declare(strict_types=1);

namespace Vortos\Health\Uptime\Driver\BetterStack;

/**
 * Real HTTP transport via curl — bounded 2s connect / 5s total timeout (mirroring
 * {@see \Vortos\Observability\Heartbeat\HttpHeartbeatEmitter}). Never throws: any
 * curl-level failure is reported as status 0 with an empty body.
 */
final class CurlBetterStackTransport implements BetterStackTransportInterface
{
    public function __construct(
        private readonly int $connectTimeoutSeconds = 2,
        private readonly int $totalTimeoutSeconds = 5,
    ) {}

    public function request(string $method, string $url, array $headers, ?array $jsonBody): array
    {
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'body' => ''];
        }

        $handle = curl_init();
        if ($handle === false) {
            return ['status' => 0, 'body' => ''];
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = sprintf('%s: %s', $name, $value);
        }

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSeconds,
            CURLOPT_TIMEOUT => $this->totalTimeoutSeconds,
        ];

        if ($jsonBody !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($jsonBody, JSON_THROW_ON_ERROR);
        }

        curl_setopt_array($handle, $options);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if ($body === false) {
            return ['status' => 0, 'body' => ''];
        }

        return ['status' => $status, 'body' => (string) $body];
    }
}
