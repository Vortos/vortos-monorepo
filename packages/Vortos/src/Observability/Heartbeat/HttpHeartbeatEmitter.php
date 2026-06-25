<?php

declare(strict_types=1);

namespace Vortos\Observability\Heartbeat;

/**
 * Pushes heartbeat check-ins over HTTP to an external dead-man monitor (Better Stack
 * heartbeat, healthchecks.io, …). The base URL is read from the environment at use
 * time and never logged. Hard connect + total timeouts so a slow monitor can't stall
 * the scheduler that drives it.
 *
 * No provider name appears here — the monitor URL is supplied by config; this is the
 * agnostic push side. Absence detection (the page) is the external monitor's job.
 */
final class HttpHeartbeatEmitter implements HeartbeatEmitterInterface
{
    public function __construct(
        private readonly string $baseUrlEnvVar = 'OBSERVABILITY_HEARTBEAT_URL',
        private readonly int $connectTimeoutSeconds = 2,
        private readonly int $totalTimeoutSeconds = 5,
    ) {}

    public function emit(HeartbeatPing $ping): bool
    {
        $base = $this->baseUrl();
        if ($base === null || !function_exists('curl_init')) {
            return false;
        }

        $url = rtrim($base, '/') . $ping->status->urlSuffix();

        $handle = curl_init();
        if ($handle === false) {
            return false;
        }

        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $ping->note ?? '',
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

    private function baseUrl(): ?string
    {
        $value = $_ENV[$this->baseUrlEnvVar] ?? $_SERVER[$this->baseUrlEnvVar] ?? getenv($this->baseUrlEnvVar);
        if (!is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }
}
