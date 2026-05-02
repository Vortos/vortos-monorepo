<?php

declare(strict_types=1);

namespace Vortos\Foundation\Health\Http;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Vortos\Foundation\Health\HealthRegistry;
use Vortos\Http\Attribute\ApiController;

#[ApiController]
#[Route('/health', name: 'vortos.health', methods: ['GET'])]
final class HealthController
{
    public function __construct(private readonly HealthRegistry $registry) {}

    public function __invoke(Request $request): JsonResponse
    {
        $results  = $this->registry->run();
        $healthy  = $this->registry->isHealthy($results);
        $detailed = $this->isDetailedRequest($request);

        $checks = [];
        foreach ($results as $name => $result) {
            $checks[$name] = $detailed
                ? $result->toDetailedArray()
                : $result->toPublicArray();
        }

        return new JsonResponse(
            [
                'status'    => $healthy ? 'ok' : 'degraded',
                'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                'checks'    => $checks,
            ],
            $healthy ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE,
        );
    }

    private function isDetailedRequest(Request $request): bool
    {
        $token = $_ENV['HEALTH_TOKEN'] ?? null;

        if ($token === null || $token === '') {
            return false;
        }

        return hash_equals($token, (string) $request->headers->get('X-Health-Token', ''));
    }
}
