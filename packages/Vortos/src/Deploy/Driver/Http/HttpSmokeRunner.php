<?php

declare(strict_types=1);

namespace Vortos\Deploy\Driver\Http;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Vortos\Deploy\Compose\ColorEndpoint;
use Vortos\Deploy\Gate\SmokeCheck;
use Vortos\Deploy\Gate\SmokeCheckResult;
use Vortos\Deploy\Gate\SmokeResult;
use Vortos\Deploy\Gate\SmokeRunnerInterface;
use Vortos\Deploy\Gate\SmokeSpec;
use Vortos\Deploy\Target\ActiveColor;

final class HttpSmokeRunner implements SmokeRunnerInterface
{
    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
    ) {}

    public function run(ActiveColor $color, ColorEndpoint $endpoint, SmokeSpec $spec): SmokeResult
    {
        if ($spec->checks === []) {
            return new SmokeResult(passed: true);
        }

        $results = [];
        $allPassed = true;

        foreach ($spec->checks as $check) {
            $result = $this->runCheck($endpoint, $check);
            $results[] = $result;

            if (!$result->passed) {
                $allPassed = false;
            }
        }

        return new SmokeResult(passed: $allPassed, checks: $results);
    }

    private function runCheck(ColorEndpoint $endpoint, SmokeCheck $check): SmokeCheckResult
    {
        $start = microtime(true);

        try {
            $request = $this->requestFactory->createRequest(
                'GET',
                $endpoint->toUrl($check->path),
            );

            $response = $this->httpClient->sendRequest($request);
            $latency = microtime(true) - $start;
            $statusCode = $response->getStatusCode();
            $passed = $statusCode === $check->expectedStatus;
            $reason = '';

            if (!$passed) {
                $reason = sprintf('Expected status %d, got %d', $check->expectedStatus, $statusCode);
            }

            if ($passed && $check->maxLatencySeconds !== null && $latency > $check->maxLatencySeconds) {
                $passed = false;
                $reason = sprintf('Latency %.3fs exceeded max %.3fs', $latency, $check->maxLatencySeconds);
            }

            return new SmokeCheckResult(
                path: $check->path,
                passed: $passed,
                statusCode: $statusCode,
                latency: $latency,
                reason: $reason,
            );
        } catch (ClientExceptionInterface $e) {
            return new SmokeCheckResult(
                path: $check->path,
                passed: false,
                statusCode: 0,
                latency: microtime(true) - $start,
                reason: 'HTTP error: ' . $e->getMessage(),
            );
        }
    }
}
