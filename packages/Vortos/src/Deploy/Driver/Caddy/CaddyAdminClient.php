<?php

declare(strict_types=1);

namespace Vortos\Deploy\Driver\Caddy;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Vortos\Deploy\Exception\CutoverFailedException;

final class CaddyAdminClient
{
    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly string $adminBaseUrl = 'http://localhost:2019',
    ) {}

    /** @param array<string, mixed> $config */
    public function load(array $config): void
    {
        $json = json_encode($config, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);

        $request = $this->requestFactory->createRequest('POST', $this->adminBaseUrl . '/load')
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
        $request = $this->requestFactory->createRequest('GET', $this->adminBaseUrl . '/config/');

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
        $request = $this->requestFactory->createRequest('GET', $this->adminBaseUrl . '/metrics');

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (\Throwable $e) {
            throw CutoverFailedException::metricsUnreachable($e->getMessage());
        }

        $body = (string) $response->getBody();
        if (preg_match('/caddy_http_requests_in_flight\s+(\d+)/', $body, $m)) {
            return (int) $m[1];
        }

        throw CutoverFailedException::metricsUnavailable();
    }
}
