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

    /**
     * Body status values that mean "ready to receive traffic" on an HTTP 200.
     *
     * The framework's canonical readiness endpoint is served by vortos-health, whose report uses the
     * probe vocabulary: pass (all good) and warn (degraded but serving) both return HTTP 200 and
     * are ready; fail returns 503 and is not. ok/ready are also accepted so the gate speaks the
     * generic vocabulary too — a color is ready when the endpoint says 200 AND its status is one of
     * these. fail/degraded (or any 503) keep the gate polling.
     */
    private const READY_STATUSES = ['ok', 'ready', 'pass', 'warn'];

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
                    if (is_string($status) && in_array($status, self::READY_STATUSES, true)) {
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
