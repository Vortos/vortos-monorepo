<?php

declare(strict_types=1);

namespace Vortos\Deploy\Driver\Caddy;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Vortos\Deploy\Exception\CutoverFailedException;
use Vortos\Deploy\Execution\SshTransportInterface;

final class CaddyAdminClient
{
    private ?string $resolvedBaseUrl = null;

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly string $adminBaseUrl = 'http://localhost:2019',
        private readonly ?SshTransportInterface $sshTransport = null,
        private readonly int $remoteAdminPort = 2019,
    ) {}

    /**
     * The base URL for admin calls. In the push model Caddy's admin API is bound to the
     * VPS loopback only (never exposed); we reach it by opening an SSH local port-forward
     * once and talking to the tunneled 127.0.0.1:<port>. In local mode we use the static
     * admin URL directly. Either way callers below are unchanged.
     */
    private function baseUrl(): string
    {
        if ($this->sshTransport === null) {
            return $this->adminBaseUrl;
        }

        return $this->resolvedBaseUrl
            ??= sprintf('http://127.0.0.1:%d', $this->sshTransport->openLocalForward($this->remoteAdminPort));
    }

    /** @param array<string, mixed> $config */
    public function load(array $config): void
    {
        $json = json_encode($config, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);

        $request = $this->requestFactory->createRequest('POST', $this->baseUrl() . '/load')
            ->withHeader('Content-Type', 'application/json');

        $request->getBody()->write($json);

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (\Throwable $e) {
            throw CutoverFailedException::adminUnreachable($e->getMessage());
        }

        if ($response->getStatusCode() >= 400) {
            throw CutoverFailedException::reloadFailed(sprintf(
                'HTTP %d: %s',
                $response->getStatusCode(),
                (string) $response->getBody(),
            ));
        }
    }

    /** @return array<string, mixed> */
    public function currentConfig(): array
    {
        $request = $this->requestFactory->createRequest('GET', $this->baseUrl() . '/config/');

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (\Throwable $e) {
            throw CutoverFailedException::adminUnreachable($e->getMessage());
        }

        $body = (string) $response->getBody();
        if ($body === '' || $body === 'null') {
            return [];
        }

        return json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
    }

    public function activeRequests(): int
    {
        $request = $this->requestFactory->createRequest('GET', $this->baseUrl() . '/metrics');

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (\Throwable $e) {
            throw CutoverFailedException::metricsUnreachable($e->getMessage());
        }

        $body = (string) $response->getBody();

        // Sum the gauge across every server/handler label set. Caddy exports
        // caddy_http_requests_in_flight per HTTP server, and the labeled time-series is only
        // materialised once a request has been observed, so an idle or freshly-reconfigured edge
        // legitimately omits the line entirely.
        if (preg_match_all('/^caddy_http_requests_in_flight(?:\{[^}]*\})?\s+(\d+)/m', $body, $mm) > 0) {
            return array_sum(array_map('intval', $mm[1]));
        }

        // A reachable /metrics with no in-flight gauge means the endpoint is up but no request is
        // being tracked — i.e. zero in flight. Per-server HTTP metrics are opt-in on Caddy 2.7+
        // (and the labeled gauge appears lazily on first request), so its absence is NOT a failure.
        // Aborting a fully health-checked + smoke-passed cutover here would be a false positive;
        // only a genuinely unreachable endpoint (the transport throw above) is fatal.
        return 0;
    }
}
