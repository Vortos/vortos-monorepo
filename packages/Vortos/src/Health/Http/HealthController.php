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

    private function respond(HealthReport $report, ?Request $request = null): JsonResponse
    {
        $detailed = $request !== null && $this->detailPolicy->allowsDetails($request);
        $includeErrors = $this->detailPolicy->allowsRawErrors();

        $data = $detailed
            ? $report->toDetailedArray($includeErrors)
            : $report->toPublicArray();

        $response = new JsonResponse($data, $report->httpStatusCode());
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
