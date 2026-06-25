<?php

declare(strict_types=1);

namespace Vortos\AnalyticsPosthog;

use RuntimeException;
use Throwable;
use Vortos\Alerts\Notifier\Driver\Webhook\SsrfGuard;
use Vortos\Analytics\Transport\AnalyticsTransportInterface;

/**
 * Bounded HTTP transport for the PostHog driver, using PHP's curl extension (no new
 * dependency, §14.2). Mirrors `Observability\Driver\Glitchtip\CurlErrorTransport`.
 *
 * Egress is SSRF-hardened: reuses the Block 17 {@see SsrfGuard} when `vortos-alerts`
 * is installed (guarded by `class_exists()`); otherwise falls back to a minimal
 * https-only check so the transport is never unguarded. Never throws — `send()`
 * returns false on any failure, including a rejected destination.
 */
final class CurlAnalyticsTransport implements AnalyticsTransportInterface
{
    public function __construct(
        private readonly int $connectTimeoutSeconds = 2,
        private readonly int $totalTimeoutSeconds = 5,
    ) {}

    public function send(string $url, string $jsonBody, array $headers): bool
    {
        try {
            $this->assertSafe($url);

            if (!function_exists('curl_init')) {
                return false;
            }

            $handle = curl_init();
            if ($handle === false) {
                return false;
            }

            $headerLines = ['Content-Type: application/json'];
            foreach ($headers as $name => $value) {
                $headerLines[] = "{$name}: {$value}";
            }

            curl_setopt_array($handle, [
                CURLOPT_URL => $url,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $jsonBody,
                CURLOPT_HTTPHEADER => $headerLines,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSeconds,
                CURLOPT_TIMEOUT => $this->totalTimeoutSeconds,
                CURLOPT_FAILONERROR => true,
                CURLOPT_FOLLOWLOCATION => false,
            ]);

            $ok = curl_exec($handle) !== false;
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

            return $ok && $status >= 200 && $status < 300;
        } catch (Throwable) {
            return false;
        }
    }

    /** @throws RuntimeException when the destination is unsafe */
    private function assertSafe(string $url): void
    {
        if (class_exists(SsrfGuard::class)) {
            (new SsrfGuard())->assertSafe($url);

            return;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'https') {
            throw new RuntimeException("Analytics transport URL must use https, got '{$scheme}'.");
        }
    }
}
