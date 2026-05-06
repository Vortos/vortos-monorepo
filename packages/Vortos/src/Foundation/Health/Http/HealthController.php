<?php

declare(strict_types=1);

namespace Vortos\Foundation\Health\Http;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Vortos\Foundation\Health\HealthDetailPolicy;
use Vortos\Foundation\Health\HealthRegistry;
use Vortos\Http\Attribute\ApiController;

#[ApiController]
final class HealthController
{
    public function __construct(
        private readonly HealthRegistry $registry,
        private readonly HealthDetailPolicy $detailPolicy,
    ) {}

    #[Route('/health', name: 'vortos.health', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        return $this->checksResponse($request, 'health', false);
    }

    #[Route('/health/live', name: 'vortos.health.live', methods: ['GET'])]
    public function live(): JsonResponse
    {
        return new JsonResponse(
            [
                'status' => 'ok',
                'mode' => 'live',
                'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            ],
            Response::HTTP_OK,
        );
    }

    #[Route('/health/ready', name: 'vortos.health.ready', methods: ['GET'])]
    public function ready(Request $request): JsonResponse
    {
        return $this->checksResponse($request, 'ready', true);
    }

    private function checksResponse(Request $request, string $mode, bool $criticalOnly): JsonResponse
    {
        $results = $this->registry->run($criticalOnly);
        $healthy = $this->registry->isHealthy($results);
        $detailed = $this->detailPolicy->allowsDetails($request);
        $includeRawErrors = $this->detailPolicy->allowsRawErrors();
        $checks = [];

        foreach ($results as $name => $result) {
            $checks[$name] = $detailed
                ? $result->toDetailedArray($includeRawErrors)
                : $result->toPublicArray();
        }

        return new JsonResponse(
            [
                'status' => $healthy ? 'ok' : 'degraded',
                'mode' => $mode,
                'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                'checks' => $checks,
            ],
            $healthy ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE,
        );
    }
}
