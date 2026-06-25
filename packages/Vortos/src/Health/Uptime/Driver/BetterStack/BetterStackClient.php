<?php

declare(strict_types=1);

namespace Vortos\Health\Uptime\Driver\BetterStack;

use Vortos\Health\Uptime\Exception\MonitorSyncException;
use Vortos\Health\Uptime\Exception\UptimeMonitorException;

/**
 * Thin client over the Better Stack Uptime API. The API token is read from the
 * environment at use time (never stored beyond the call, never logged); any error
 * string is redacted before it reaches an exception message (§6.3).
 */
final class BetterStackClient
{
    private const BASE_URL = 'https://uptime.betterstack.com/api/v2/monitors';

    public function __construct(
        private readonly BetterStackTransportInterface $transport,
        private readonly string $tokenEnvVar = 'UPTIME_MONITOR_BETTERSTACK_TOKEN',
    ) {}

    /** @param array<string, mixed> $payload */
    public function createOrUpdateMonitor(string $externalId, array $payload): string
    {
        $token = $this->token();
        if ($token === null) {
            throw MonitorSyncException::forFailure($externalId, 'missing API token (' . $this->tokenEnvVar . ')');
        }

        $response = $this->transport->request(
            'POST',
            self::BASE_URL,
            $this->authHeaders($token),
            [...$payload, 'external_id' => $externalId],
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw MonitorSyncException::forFailure(
                $externalId,
                $this->redact(sprintf('HTTP %d: %s', $response['status'], $response['body']), $token),
            );
        }

        $decoded = json_decode($response['body'], true);
        $id = is_array($decoded) ? ($decoded['data']['id'] ?? null) : null;

        if (!is_string($id) || $id === '') {
            throw MonitorSyncException::forFailure($externalId, 'malformed response: missing data.id');
        }

        return $id;
    }

    /** @return array<string, mixed> */
    public function monitorStatus(string $monitorId): array
    {
        $token = $this->token();
        if ($token === null) {
            throw new UptimeMonitorException(sprintf('Cannot read status for "%s": missing API token (%s).', $monitorId, $this->tokenEnvVar));
        }

        $response = $this->transport->request('GET', self::BASE_URL . '/' . rawurlencode($monitorId), $this->authHeaders($token), null);

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new UptimeMonitorException($this->redact(
                sprintf('Failed to read status for "%s": HTTP %d: %s', $monitorId, $response['status'], $response['body']),
                $token,
            ));
        }

        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded)) {
            throw new UptimeMonitorException(sprintf('Malformed JSON status response for "%s".', $monitorId));
        }

        return $decoded;
    }

    private function token(): ?string
    {
        $value = $_ENV[$this->tokenEnvVar] ?? $_SERVER[$this->tokenEnvVar] ?? getenv($this->tokenEnvVar);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return array<string, string> */
    private function authHeaders(string $token): array
    {
        return ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'];
    }

    private function redact(string $message, string $token): string
    {
        return $token === '' ? $message : str_replace($token, '[redacted]', $message);
    }
}
