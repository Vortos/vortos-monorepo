<?php

declare(strict_types=1);

namespace Vortos\Health\Http;

use Symfony\Component\Routing\Attribute\Route;
use Vortos\Foundation\Health\HealthDetailPolicy;
use Vortos\Health\Aggregator\HealthAggregator;
use Vortos\Health\Aggregator\HealthReport;
use Vortos\Http\Attribute\AsController;
use Vortos\Http\JsonResponse;
use Vortos\Http\Request;
use Vortos\Http\Response;

#[AsController]
final class HealthController
{
    public function __construct(
        private readonly HealthAggregator $aggregator,
        private readonly HealthDetailPolicy $detailPolicy,
    ) {}

    #[Route('/health', name: 'vortos.health', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        return $this->respond($this->aggregator->ready(), $request);
    }

    #[Route('/health/live', name: 'vortos.health.live', methods: ['GET'])]
    public function live(): JsonResponse
    {
        $report = $this->aggregator->live();

        return $this->respond($report);
    }

    #[Route('/health/ready', name: 'vortos.health.ready', methods: ['GET'])]
    public function ready(Request $request): JsonResponse
    {
        return $this->respond($this->aggregator->ready(), $request);
    }

    #[Route('/health/startup', name: 'vortos.health.startup', methods: ['GET'])]
    public function startup(Request $request): JsonResponse
    {
        return $this->respond($this->aggregator->startup(), $request);
    }

    /**
     * Informational monitoring surface (GAP-F): cert-expiry and other Monitoring-kind probes. Always
     * returns 200 — it is NOT a readiness gate. Scrape the body for per-probe pass/warn/fail.
     */
    #[Route('/health/monitor', name: 'vortos.health.monitor', methods: ['GET'])]
    public function monitor(Request $request): JsonResponse
    {
        return $this->respond($this->aggregator->monitor(), $request);
    }

    /**
     * R8-5: authenticated full per-check breakdown. Requires a valid X-Health-Token REGARDLESS of the
     * HEALTH_DETAILS policy — so an operator can always diagnose a warn/fail over HTTP, even when the
     * anonymous policy is "never". A missing/invalid token returns 401 (authorization failure), which
     * is deliberately distinct from a health status so scrapers never mistake it for unreadiness, and
     * unauthenticated callers learn nothing. Body carries the same readiness report as /health/ready.
     */
    #[Route('/health/detail', name: 'vortos.health.detail', methods: ['GET'])]
    public function detail(Request $request): JsonResponse
    {
        if (!$this->detailPolicy->matchesToken($request)) {
            $body = $this->detailPolicy->hasToken()
                ? ['error' => 'invalid or missing X-Health-Token']
                : ['error' => 'health detail is not configured (set HEALTH_TOKEN)'];

            $response = new JsonResponse($body, 401);
            $response->headers->set('WWW-Authenticate', 'Bearer realm="health"');
            $this->applyNoCache($response);

            return $response;
        }

        $report = $this->aggregator->ready();
        $response = new JsonResponse(
            $report->toDetailedArray($this->detailPolicy->allowsRawErrors()),
            $report->httpStatusCode(),
        );
        $this->applyNoCache($response);

        return $response;
    }

    private function respond(HealthReport $report, ?Request $request = null): JsonResponse
    {
        $detailed = $request !== null && $this->detailPolicy->allowsDetails($request);
        $includeErrors = $this->detailPolicy->allowsRawErrors();

        $data = $detailed
            ? $report->toDetailedArray($includeErrors)
            : $report->toPublicArray($this->detailPolicy->allowsPublicDegradedNames());

        $response = new JsonResponse($data, $report->httpStatusCode());
        $this->applyNoCache($response);

        return $response;
    }

    private function applyNoCache(JsonResponse $response): void
    {
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate');
        $response->headers->set('Pragma', 'no-cache');
    }
}
