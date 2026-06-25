<?php

declare(strict_types=1);

namespace Vortos\Observability\Marker;

use RuntimeException;

final class CurlMarkerTransport implements MarkerTransportInterface
{
    public function post(string $url, array $body, array $headers): void
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Failed to initialize curl handle for marker transport.');
        }

        $headerLines = ['Content-Type: application/json'];
        foreach ($headers as $name => $value) {
            $headerLines[] = "{$name}: {$value}";
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);

        curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($errno !== 0) {
            throw new RuntimeException("Marker transport curl error (errno {$errno}) posting to {$url}.");
        }
    }
}
