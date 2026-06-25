<?php

declare(strict_types=1);

namespace Vortos\Deploy\Driver\Http;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Vortos\Deploy\Compose\ColorEndpoint;
use Vortos\Deploy\Gate\GateBudget;
use Vortos\Deploy\Gate\GateResult;
use Vortos\Deploy\Gate\ReadinessGateInterface;
use Vortos\Deploy\Target\ActiveColor;

final class HttpReadinessGate implements ReadinessGateInterface
{
    private const READY_PATH = '/health/ready';

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
    ) {}

    public function awaitReady(ActiveColor $color, ColorEndpoint $endpoint, GateBudget $budget): GateResult
    {
        $start = microtime(true);
        $attempts = 0;
        $lastStatus = null;
        $deadline = $start + $budget->timeout;

        while ($attempts < $budget->maxAttempts && microtime(true) < $deadline) {
            $attempts++;

            try {
                $request = $this->requestFactory->createRequest(
                    'GET',
                    $endpoint->toUrl(self::READY_PATH),
                );

                $response = $this->httpClient->sendRequest($request);
                $lastStatus = $response->getStatusCode();

                if ($lastStatus === 200) {
                    $body = (string) $response->getBody();
                    $data = json_decode($body, true);

                    $status = $data['status'] ?? null;
                    if ($status === 'ok' || $status === 'ready') {
                        return new GateResult(
                            passed: true,
                            attempts: $attempts,
                            elapsed: microtime(true) - $start,
                            lastStatusCode: $lastStatus,
                        );
                    }
                }
            } catch (ClientExceptionInterface) {
                // Connection refused, timeout — keep polling
            }

            if ($attempts < $budget->maxAttempts && microtime(true) < $deadline) {
                $jitter = $budget->interval * (0.5 + (mt_rand() / mt_getrandmax()) * 0.5);
                $sleepUntil = microtime(true) + $jitter;
                if ($sleepUntil > $deadline) {
                    break;
                }
                usleep((int) ($jitter * 1_000_000));
            }
        }

        return new GateResult(
            passed: false,
            attempts: $attempts,
            elapsed: microtime(true) - $start,
            lastStatusCode: $lastStatus,
        );
    }
}
